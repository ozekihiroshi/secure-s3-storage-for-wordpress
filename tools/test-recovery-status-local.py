#!/usr/bin/env python3
"""Disposable WP-CLI/MariaDB status test. Local images, internal network, no AWS."""
import hashlib, ipaddress, json, pathlib, re, subprocess, sys, time, uuid

root = pathlib.Path(__file__).resolve().parents[1]
name = 'odb-status-' + uuid.uuid4().hex[:10]
network, volume, db = name+'-net', name+'-site', name+'-db'
private = name+'-inspection'
created = []
def run(*args, code=0):
    p = subprocess.run(args, text=True, capture_output=True, timeout=120)
    if p.returncode != code:
        raise RuntimeError(f'{args[0:3]} expected {code}, got {p.returncode}: {p.stderr[:1000]}')
    return p.stdout.strip()
def wp(*args, code=0):
    return run('docker','run','--rm','--network',network,'--user','0:0',
        '-e','ODBFS3_STATUS_FIXTURE=1','-v',volume+':/var/www/html',
        '-v',private+':/inspection',
        '-v',str(root)+':/repo:ro','wordpress:cli-php8.3','wp','--allow-root',
        '--require=/repo/tests/manual/status-cli-bootstrap.php',*args,code=code)
try:
    networks=json.loads(run('docker','network','inspect',*run('docker','network','ls','-q').split()))
    used=[ipaddress.ip_network(c['Subnet']) for n in networks for c in n['IPAM']['Config'] or [] if c.get('Subnet')]
    routes=json.loads(run('ip','-j','route'))
    used += [ipaddress.ip_network(r['dst'],strict=False) for r in routes if r.get('dst') and r['dst']!='default']
    subnet=next(str(n) for n in ipaddress.ip_network('10.254.0.0/16').subnets(new_prefix=28)
                if not any(n.version==u.version and n.overlaps(u) for u in used))
    run('docker','network','create','--internal','--subnet',subnet,network); created.append(('network',network))
    run('docker','volume','create',volume); created.append(('volume',volume))
    run('docker','volume','create',private); created.append(('volume',private))
    run('docker','run','--rm','--network','none','--entrypoint','sh','-v',volume+':/site',
        'wordpress:php8.3-apache','-c','cp -a /usr/src/wordpress/. /site/')
    run('docker','run','-d','--name',db,'--network',network,
        '-e','MARIADB_ROOT_PASSWORD=fixture-only','-e','MARIADB_DATABASE=fixture',
        'mariadb:11.8'); created.append(('container',db))
    for attempt in range(60):
        p=subprocess.run(['docker','exec',db,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True)
        if p.returncode==0: break
        time.sleep(1)
    else: raise RuntimeError('Disposable database not ready')
    wp('config','create','--dbname=fixture','--dbuser=root','--dbpass=fixture-only','--dbhost='+db,'--skip-check')
    wp('config','set','DISABLE_WP_CRON','true','--raw')
    wp('config','set','AUTOMATIC_UPDATER_DISABLED','true','--raw')
    wp('core','install','--url=http://fixture.invalid','--title=Fixture','--admin_user=fixture',
        '--admin_password=fixture-only-long-password','--admin_email=fixture@example.invalid','--skip-email')
    sid=wp('eval-file','/repo/tests/manual/seed-recovery-status.php')
    workspace_before=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'setup')
    def snapshot(ignore_cleanup=False):
        data=run('docker','exec',db,'mariadb-dump','-uroot','-pfixture-only',
                 '--skip-comments','--skip-dump-date','--skip-extended-insert','--order-by-primary','fixture')
        if ignore_cleanup:
            data='\n'.join(line for line in data.splitlines() if not (line.startswith('INSERT INTO `wp_options`') and 'odbfs3_cleanup_' in line))
            # Creating the one journal row also advances wp_options' next generated ID.
            data=re.sub(r'(CREATE TABLE `wp_options` .*?AUTO_INCREMENT=)\d+',r'\g<1>JOURNAL_COUNTER',data,flags=re.S)
        return hashlib.sha256(data.encode()).hexdigest()
    before=snapshot()
    if '--admin-only' in sys.argv:
        wp('eval-file','/repo/tests/manual/seed-recovery-catalog.php',sid)
        print(wp('eval-file','/repo/tests/manual/test-recovery-admin-wordpress.php',sid))
        assert wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'snapshot')==workspace_before
        print('PASS admin rendering leaves workspace files unchanged')
        raise SystemExit(0)
    cleanup=('ozeki-database-backup-for-s3','recovery-set','cleanup-plan')
    plan=json.loads(wp(*cleanup,sid,'--workspace-parent=/inspection','--format=json'))
    assert plan['inspection_complete'] and plan['candidate_bytes']==27
    assert len([e for e in plan['entries'] if e['disposition']=='candidate'])==2
    assert 'candidate_bytes' in wp(*cleanup,sid,'--workspace-parent=/inspection')
    missing_plan=json.loads(wp(*cleanup,'f'*32,'--workspace-parent=/inspection','--format=json',code=3))
    assert missing_plan['protection_reasons']==['set_not_found']
    invalid_plan=json.loads(wp(*cleanup,sid,'--workspace-parent=/does-not-exist','--format=json',code=4))
    assert not invalid_plan['inspection_complete']
    assert wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'snapshot')==workspace_before
    assert snapshot()==before, 'Cleanup plan changed database'
    print('PASS cleanup-plan WP CLI: JSON/table/missing/unsafe-parent; files and DB unchanged')
    if '--cleanup-execute-only' in sys.argv:
        wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'prepare-execution')
        original=snapshot(True)
        evidence=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'evidence')
        execute=('ozeki-database-backup-for-s3','recovery-set','cleanup',sid,'--workspace-parent=/inspection','--format=json')
        assert wp(*execute,code=1)=='' # WP-CLI required-argument parser rejects before dispatch.
        no_confirm=json.loads(wp(*execute,'--confirm-set='+'f'*32,code=2))
        assert no_confirm['reason_code']=='invalid_request' and snapshot(True)==original
        result=json.loads(wp(*execute,'--confirm-set='+sid))
        assert result['state']=='complete' and set(result['files'].values())=={'deleted'}
        assert snapshot(True)==original, 'Only cleanup journal may change in DB'
        assert wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'evidence')==evidence
        after=snapshot()
        assert json.loads(wp(*execute,'--confirm-set='+sid))['state']=='complete'
        assert snapshot()==after, 'Replay must not update even the journal'
        assert json.loads(wp(*cleanup,sid,'--workspace-parent=/inspection','--format=json'))['candidate_bytes']==0
        print(json.dumps({'kind':'cleanup_execution_real_wordpress_cli','passed':True,'only_cleanup_journal_changed':True,'evidence_preserved':True,'replay_unchanged':True,'remaining_candidate_bytes':0,'external_network':'blocked','bootstrap':'test-only registrar, synthetic Collector fixture'}))
        raise SystemExit(0)
    if '--cleanup-only' in sys.argv:
        print(json.dumps({'kind':'cleanup_plan_real_wordpress_cli','passed':True,'files_unchanged':True,'database_unchanged':True,'cases':4,'external_network':'blocked','bootstrap':'test-only registrar'}))
        raise SystemExit(0)
    command=('ozeki-database-backup-for-s3','recovery-set','status')
    result=json.loads(wp(*command,sid,'--format=json'))
    assert result['phase']=='complete' and result['manifest']['publication']=='verified'
    assert result['availability']['state']=='unknown' and result['restore']['state']=='not_tested'
    assert result['assets']['media_set_id']==sid
    table=wp(*command,sid)
    assert 'complete' in table and 'observation_scope' in table
    missing=json.loads(wp(*command,'f'*32,'--format=json',code=3))
    assert missing['reason_code']=='set_not_found'
    corrupt=json.loads(wp(*command,'e'*32,'--format=json',code=4))
    assert corrupt['reason_code']=='state_unreadable' and 'private' not in json.dumps(corrupt)
    bad=json.loads(wp(*command,'invalid','--format=json',code=2))
    assert bad['reason_code']=='invalid_request'
    assert snapshot()==before, 'WordPress database changed during status reads'
    group=('ozeki-database-backup-for-s3','recovery-set')
    source=result['source_id']
    incomplete=json.loads(wp(*group,'latest','--source-id='+source,'--format=json',code=4))
    assert not incomplete['index_complete'] and incomplete['latest_complete'] is None
    assert snapshot()==before, 'Incomplete-index read changed database'
    wp('eval-file','/repo/tests/manual/seed-recovery-catalog.php',sid)
    before=snapshot()
    latest=json.loads(wp(*group,'latest','--source-id='+source,'--format=json'))
    assert latest['index_complete'] and latest['latest_attempt']['phase']=='failed'
    assert latest['latest_complete']['set_id']==sid
    empty=json.loads(wp(*group,'latest','--source-id='+source,'--profile=database','--format=json',code=3))
    assert empty['latest_attempt'] is None
    obs=json.loads(wp(*group,'observations',sid,'--format=json'))
    assert obs['aggregation_complete'] and obs['availability']['state']=='available'
    assert obs['restore']['state']=='failed' and obs['records_scanned']==2
    stale=json.loads(wp(*group,'observations',sid,'--max-age=1','--format=json'))
    assert stale['availability']['state']=='unknown' and stale['availability']['recorded_state']=='available'
    assert stale['availability']['freshness']=='stale'
    assert snapshot()==before, 'Catalog/observation reads changed database'
    print(json.dumps({'kind':'recovery_status_real_wordpress_cli','passed':True,
        'database_unchanged':True,'cases':['complete_json','complete_table','missing','corrupt','invalid_id'],
        'catalog_cases':['incomplete_index','latest_attempt_vs_complete','profile_filter','observations','stale_observation'],
        'observation_append_idempotent':True,'completion_evidence_preserved':True,
        'external_network':'blocked','bootstrap':'test-only registrar; plugin workers not loaded'}))
finally:
    for kind,item in reversed(created):
        subprocess.run(['docker',kind,'rm',*(['-f','-v'] if kind=='container' else []),item],capture_output=True,check=True)
