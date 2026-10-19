#!/usr/bin/env python3
"""Disposable WP-CLI/MariaDB status test. Local images, internal network, no AWS."""
import os, hashlib, ipaddress, json, pathlib, re, subprocess, sys, time, uuid

root = pathlib.Path(__file__).resolve().parents[1]
name = 'odb-status-' + uuid.uuid4().hex[:10]
network, volume, db = name+'-net', name+'-site', name+'-db'
private = name+'-inspection'
created = []
state=pathlib.Path(os.environ.get('ODBFS3_BROWSER_STATE',str(root.parent/'release-readiness/recovery-browser-state.json')))
vendor=pathlib.Path(os.environ.get('ODBFS3_TEST_VENDOR',str(root.parent.parent/'secure-s3-storage-for-wordpress/vendor')))
def run(*args, code=0):
    p = subprocess.run(args, text=True, capture_output=True, timeout=120)
    if p.returncode != code:
        raise RuntimeError(f'{args[0:3]} expected {code}, got {p.returncode}: {p.stderr[:1000]}')
    return p.stdout.strip()
def wp(*args, code=0):
    return run('docker','run','--rm','--network',network,'--user','0:0',
        '-e','ODBFS3_STATUS_FIXTURE=1','-v',volume+':/var/www/html',
        '-v',private+':/inspection',
        '-v',str(root)+':/repo:ro','-v',str(root)+':/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3:ro','-v',str(vendor)+':/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/vendor:ro','wordpress:cli-php8.3','wp','--allow-root',
        *args,code=code)
def setup():
    if state.exists():raise RuntimeError('Existing fixture state: inspect or stop it first')
    if not (vendor/'autoload.php').is_file():raise RuntimeError('Set ODBFS3_TEST_VENDOR to a complete Composer vendor directory')
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
        wp('core','install','--url=http://localhost:18763','--title=Fixture','--admin_user=fixture',
            '--admin_password=fixture-only-long-password','--admin_email=fixture@example.invalid','--skip-email')
        wp('config','set','WP_HTTP_BLOCK_EXTERNAL','true','--raw')
        wp('plugin','activate','ozeki-database-backup-for-s3')
        wp('user','create','fixtureviewer','viewer@example.invalid','--role=subscriber','--user_pass=fixture-only-long-password')
        sid=wp('eval-file','/repo/tests/manual/seed-recovery-status.php')
        workspace_before=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'setup')
        wp('eval-file','/repo/tests/manual/seed-recovery-catalog.php',sid)
        web=name+'-web'
        run('docker','run','-d','--name',web,'--network',network,'-p','127.0.0.1:18763:80',
            '-v',volume+':/var/www/html','-v',private+':/inspection',
            '-v',str(root)+':/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3:ro',
            '-v',str(vendor)+':/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/vendor:ro',
            'wordpress:php8.3-apache'); created.append(('container',web))
        state.write_text(json.dumps(dict(network=network,volume=volume,private=private,db=db,created=created,set_id=sid)))
        print(wp('plugin','status','ozeki-database-backup-for-s3'))
        print(sid)
    except BaseException:
        for kind,item in reversed(created):
            subprocess.run(['docker',kind,'rm',*(['-f','-v'] if kind=='container' else []),item],capture_output=True)
        raise

if __name__=='__main__':setup()
