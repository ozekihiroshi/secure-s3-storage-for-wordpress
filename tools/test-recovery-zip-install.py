#!/usr/bin/env python3
"""Install actual release ZIPs in disposable WordPress. No source autoload or AWS."""
import os,hashlib,ipaddress,json,pathlib,subprocess,sys,time,uuid,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
candidate=pathlib.Path(sys.argv[1]).resolve();previous=[pathlib.Path(p).resolve() for p in sys.argv[2:]]
slug='ozeki-database-backup-for-s3';results=[]
def run(*args):
 p=subprocess.run(args,text=True,capture_output=True,timeout=240)
 if p.returncode:raise RuntimeError(str(args[:4])+': '+p.stderr[-2000:]+p.stdout[-1000:])
 return p.stdout.strip()
def archive_info(p):
 with zipfile.ZipFile(p) as z:
  files=[n for n in z.namelist() if not n.endswith('/')]
  assert all(n.startswith(slug+'/') and '..' not in pathlib.PurePosixPath(n).parts for n in files)
  assert not any(n.startswith(slug+'/'+x+'/') for n in files for x in ['tests','tools','docs','.git','build'])
  assert not any(n.endswith(('.pem','.key','.env')) for n in files)
  import re
  header=z.read(slug+'/'+slug+'.php').decode();version=re.search(r'Version:\s*([^\s]+)',header)[1]
  return {'version':version,'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'files':len(files)}
info=archive_info(candidate)
for old in ([] if os.environ.get('ODBFS3_SKIP_FRESH')=='1' else [None])+previous:
 name='odb-zip-'+uuid.uuid4().hex[:10];network=name+'-net';volume=name+'-site';private=name+'-private';db=name+'-db';created=[]
 def wp(*args):
  mounts=['-v',str(candidate)+':/candidate.zip:ro','-v',str(root)+':/repo:ro','-v',volume+':/var/www/html','-v',private+':/inspection']
  if old:mounts+=['-v',str(old)+':/previous.zip:ro']
  return run('docker','run','--rm','--network',network,'--user','0:0','-e','ODBFS3_STATUS_FIXTURE=1',*mounts,'wordpress:cli-php8.3','wp','--allow-root',*args)
 def snapshot(include_cron=True):
  sql="SELECT option_name,option_value,autoload FROM wp_options WHERE option_name LIKE 'odbfs3_%' OR option_name LIKE 'secure_s3_%' OR option_name IN ('cron','active_plugins') ORDER BY option_name"
  if not include_cron:sql=sql.replace("('cron','active_plugins')","('active_plugins')")
  raw=run('docker','exec',db,'mariadb','-uroot','-pfixture-only','--batch','--skip-column-names','fixture','-e',sql)
  return hashlib.sha256(raw.encode()).hexdigest()
 try:
  for attempt in range(5):
   ids=run('docker','network','ls','-q').split()
   inspected=subprocess.run(['docker','network','inspect',*ids],text=True,capture_output=True)
   if inspected.returncode==0:
    nets=json.loads(inspected.stdout);break
   time.sleep(1)
  else:raise RuntimeError('Docker network inventory changed repeatedly; retry after fixture cleanup')
  used=[ipaddress.ip_network(c['Subnet']) for n in nets for c in n['IPAM']['Config'] or [] if c.get('Subnet')]
  used += [ipaddress.ip_network(r['dst'],strict=False) for r in json.loads(run('ip','-j','route')) if r.get('dst') and r['dst']!='default']
  subnet=next(str(n) for n in ipaddress.ip_network('10.254.0.0/16').subnets(new_prefix=28) if not any(n.version==u.version and n.overlaps(u) for u in used))
  run('docker','network','create','--internal','--subnet',subnet,network);created.append(('network',network))
  for v in [volume,private]:run('docker','volume','create',v);created.append(('volume',v))
  run('docker','run','--rm','--network','none','--entrypoint','sh','-v',volume+':/site','wordpress:php8.3-apache','-c','cp -a /usr/src/wordpress/. /site/')
  run('docker','run','-d','--name',db,'--network',network,'-e','MARIADB_ROOT_PASSWORD=fixture-only','-e','MARIADB_DATABASE=fixture','mariadb:11.8');created.append(('container',db))
  for _ in range(60):
   if subprocess.run(['docker','exec',db,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True).returncode==0:break
   time.sleep(1)
  else:raise RuntimeError('Database not ready')
  wp('config','create','--dbname=fixture','--dbuser=root','--dbpass=fixture-only','--dbhost='+db,'--skip-check')
  for k in ['DISABLE_WP_CRON','AUTOMATIC_UPDATER_DISABLED','WP_HTTP_BLOCK_EXTERNAL']:wp('config','set',k,'true','--raw')
  wp('config','set','FS_METHOD','direct')
  wp('core','install','--url=http://fixture.invalid','--title=Fixture','--admin_user=fixture','--admin_password=fixture-only-long-password','--admin_email=fixture@example.invalid','--skip-email')
  wp('plugin','install','/previous.zip' if old else '/candidate.zip','--activate')
  wp('eval-file','/repo/tests/manual/recovery-zip-fixture.php','legacy')
  sid=None
  if old and archive_info(old)['version']=='0.3.0':
   sid=wp('eval-file','/repo/tests/manual/seed-recovery-status.php')
   wp('eval',"delete_option('odbfs3_recovery_'.str_repeat('e',32));")
   files_before=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'setup')
  before=snapshot()
  if old:
   wp('eval-file','/repo/tests/manual/upgrade-recovery-zip.php',info['version'])
   wp('plugin','activate',slug)
   assert snapshot()==before,'Upgrade changed existing settings/history/Cron/active/state'
   if sid:assert wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'snapshot')==files_before
  assert 'Active' in wp('plugin','status',slug)
  wp('eval',"foreach(['Admin\\RecoverySetsPage','Cli\\RecoverySetCommand','Backup\\Recovery\\RecoveryCollector','Backup\\Recovery\\RecoveryCleanup','WordPress\\WordPressRecoveryCatalog','Cron\\RecoverySetCronHandler'] as $c){$r=new ReflectionClass('SecureS3StorageForWordpress'.chr(92).$c);if(!str_starts_with($r->getFileName(),WP_PLUGIN_DIR.'/ozeki-database-backup-for-s3/'))throw new RuntimeException('Source fallback');}")
  # Check every installed file against ZIP without bringing source or external autoload into runtime.
  with zipfile.ZipFile(candidate) as z:
   expected={n[len(slug)+1:]:hashlib.sha256(z.read(n)).hexdigest() for n in z.namelist() if not n.endswith('/')}
  actual=json.loads(wp('eval',"$base=WP_PLUGIN_DIR.'/ozeki-database-backup-for-s3/';$out=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())$out[substr($f->getPathname(),strlen($base))]=hash_file('sha256',$f->getPathname());}echo json_encode($out);"))
  assert actual==expected,'Installed tree differs from ZIP'
  if sid is None:
   sid=wp('eval-file','/repo/tests/manual/seed-recovery-status.php');wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'setup')
  wp('eval-file','/repo/tests/manual/seed-recovery-catalog.php',sid)
  wp('eval-file','/repo/tests/manual/seed-recovery-browser-cleanup.php',sid)
  wp('eval-file','/repo/tests/manual/recovery-zip-fixture.php','extended',sid)
  assert wp('eval',"global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'odbfs3_expectation_%'\");")=='0','Activation unexpectedly created expectation settings'
  wp('eval-file','/repo/tests/manual/recovery-schedule-zip-fixture.php','seed',sid)
  before=snapshot();files_before=wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'snapshot')
  wp('eval-file','/repo/tests/manual/upgrade-recovery-zip.php',info['version'])
  wp('plugin','activate',slug)
  assert snapshot()==before,'Same-version replacement lost pending state'
  wp('eval-file','/repo/tests/manual/recovery-schedule-zip-fixture.php','verify',sid)
  command=('ozeki-database-backup-for-s3','recovery-set')
  for source,mode in [('a'*32,'not_due'),('b'*32,'stopped')]:
   assert json.loads(wp(*command,'schedule-status','--source-id='+source,'--format=json'))['state']==mode,'ZIP schedule JSON differs'
   assert json.loads(wp(*command,'execution','--source-id='+source,'--format=json'))['latest_run']['finished_at'] is None,'ZIP lost unfinished invocation'
  assert json.loads(wp(*command,'status',sid,'--format=json'))['phase']=='complete'
  assert 'complete' in wp(*command,'status',sid),'ZIP table formatter failed'
  assert json.loads(wp(*command,'observations',sid,'--format=json'))['restore']['state']=='failed'
  wp('eval-file','/repo/tests/manual/recovery-zip-fixture.php','view',sid)
  assert snapshot()==before,'ZIP read views changed state'
  # Deactivation intentionally unschedules legacy DB/media hooks, but must keep data.
  data_before=snapshot(False)
  wp('plugin','deactivate',slug);wp('plugin','activate',slug)
  assert snapshot(False)==data_before,'Reactivation lost state'
  wp('eval-file','/repo/tests/manual/recovery-schedule-zip-fixture.php','verify',sid)
  events=json.loads(wp('cron','event','list','--format=json'))
  assert len([e for e in events if e['hook']=='secure_s3_storage_database_backup_cron'])==1,'Legacy daily schedule not restored'
  assert wp('eval-file','/repo/tests/manual/seed-cleanup-plan.php',sid,'snapshot')==files_before
  # State-preservation assertions above are complete. Reuse only this disposable
  # source's policy for POST/nonce/CAS/permissions regression against installed code.
  wp('eval',"delete_option('odbfs3_expectation_'.str_repeat('a',32));")
  settings_result=json.loads(wp('eval-file','/repo/tests/manual/test-recovery-schedule-settings-wordpress.php'))
  assert settings_result['passed'],'Installed settings handler regression'
  result={'previous':archive_info(old) if old else None,'candidate':info,'passed':True,'installed_files_match_zip':True,'upgrade_state_preserved':True,'same_version_pending_records_preserved':True,'schedule_settings_and_execution_preserved':True,'schedule_and_execution_json':True,'read_views_unchanged':True,'deactivate_reactivate_preserved':True,'workspace_preserved':True}
  result['settings_handler_checks']=settings_result['checks']
  results.append(result);print(json.dumps(result),flush=True)
 finally:
  for kind,item in reversed(created):run('docker',kind,'rm',*(['-f','-v'] if kind=='container' else []),item)
print(json.dumps({'passed':True,'cases':results,'scope':'real ZIP Plugin_Upgrader on disposable WP; generated records; no S3'}))
