"""Authenticated admin language checks on disposable, outbound-isolated WordPress.

Usage: python3 tools/test-settings-locales.py EVIDENCE_DIRECTORY
The directory must contain the validated 0.4.2 ZIP and language-changes.tar
(entrypoint, SettingsPage.php and languages/ from the development source).
ODBFS3_TEST_DOCKER optionally selects a rootless Docker client/socket.
ODBFS3_TEST_WP_CLI must point to an existing official wp-cli.phar.
For an exact release ZIP, set ODBFS3_TEST_CANDIDATE to its absolute path;
no source overlay is applied. ODBFS3_TEST_PREVIOUS optionally installs an
older ZIP first and upgrades it before the language checks.
"""
import os,sys,shlex,uuid,pathlib,subprocess,time,zipfile,json,urllib.request,urllib.parse,hashlib,tarfile,re
base=pathlib.Path(sys.argv[1]).resolve()
d=shlex.split(os.environ.get('ODBFS3_TEST_DOCKER','docker'))
name='odb-locale-'+uuid.uuid4().hex[:10];net=name+'-net';db=name+'-db';web=name+'-web';volume=name+'-site'
def run(*args):
 p=subprocess.run(args,text=True,capture_output=True,timeout=180)
 if p.returncode:raise RuntimeError(str(args[:3])+': '+p.stderr[-2500:]+p.stdout[-1500:])
 return p.stdout.strip()
def docker(*a):return run(*d,*a)
def wp(*a):return docker('exec','--user','www-data',web,'php','/tmp/wp.phar','--path=/var/www/html',*a)
created=[]
try:
 docker('network','create','--internal',net);created.append(('network',net))
 docker('volume','create',volume);created.append(('volume',volume))
 docker('run','-d','--name',db,'--network',net,'-e','MARIADB_ROOT_PASSWORD=fixture-only','-e','MARIADB_DATABASE=fixture','-e','MARIADB_USER=fixture','-e','MARIADB_PASSWORD=fixture-only','mariadb:11.8');created.append(('container',db))
 for _ in range(60):
  if subprocess.run(d+['exec',db,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True).returncode==0:break
  time.sleep(1)
 else:raise RuntimeError('Database not ready')
 docker('run','-d','--name',web,'--network',net,'-v',volume+':/var/www/html','-e','WORDPRESS_DB_HOST='+db,'-e','WORDPRESS_DB_NAME=fixture','-e','WORDPRESS_DB_USER=fixture','-e','WORDPRESS_DB_PASSWORD=fixture-only','wordpress:php8.3-apache');created.append(('container',web))
 docker('cp',os.environ['ODBFS3_TEST_WP_CLI'],web+':/tmp/wp.phar')
 for _ in range(60):
  try:docker('exec',web,'test','-f','/var/www/html/wp-config.php');break
  except RuntimeError:time.sleep(1)
 core_version=os.environ.get('ODBFS3_TEST_CORE_VERSION')
 if core_version:
  assert re.fullmatch(r'\d+\.\d+(?:\.\d+)?',core_version)
  core=base/(name+'-wordpress.tar.gz')
  core.write_bytes(urllib.request.urlopen('https://wordpress.org/wordpress-'+core_version+'.tar.gz',timeout=90).read())
  core_dir=base/(name+'-core');core_dir.mkdir()
  with tarfile.open(core) as t:t.extractall(core_dir,filter='data')
  # Only this runner's new, empty WordPress fixture is changed.
  docker('exec',web,'rm','-rf','/var/www/html/wp-admin','/var/www/html/wp-includes','/var/www/html/wp-content')
  docker('cp',str(core_dir/'wordpress')+'/.',web+':/var/www/html/')
  docker('exec',web,'chown','-R','www-data:www-data','/var/www/html')
 wp('core','install','--url=http://localhost','--title=Language fixture','--admin_user=operator','--admin_password=fixture-only-long-password','--admin_email=fixture@example.invalid','--skip-email')
 for key in ['DISABLE_WP_CRON','AUTOMATIC_UPDATER_DISABLED','WP_HTTP_BLOCK_EXTERNAL']:
  wp('config','set',key,'true','--raw')
 exact=bool(os.environ.get('ODBFS3_TEST_CANDIDATE'))
 archive=pathlib.Path(os.environ['ODBFS3_TEST_CANDIDATE']) if exact else base/'ozeki-database-backup-for-s3-0.4.2.zip'
 if not exact:assert hashlib.sha256(archive.read_bytes()).hexdigest()=='30a227b766db693459dfb2a5b2544f95e34d5e56cbd3faeb4c0eda196876f162'
 stage=base/(name+'-staging');stage.mkdir(exist_ok=False)
 with zipfile.ZipFile(archive) as z:z.extractall(stage)
 if not exact:
  with tarfile.open(base/'language-changes.tar') as t:t.extractall(stage/'ozeki-database-backup-for-s3',filter='data')
 previous=os.environ.get('ODBFS3_TEST_PREVIOUS')
 if previous:
  assert exact,'Upgrade testing requires an exact ZIP'
  docker('cp',previous,web+':/tmp/previous.zip');wp('plugin','install','/tmp/previous.zip','--activate')
  docker('cp',str(archive),web+':/tmp/candidate.zip');wp('plugin','install','/tmp/candidate.zip','--force')
 else:
  docker('cp',str(stage/'ozeki-database-backup-for-s3'),web+':/var/www/html/wp-content/plugins/')
  docker('exec',web,'chown','-R','www-data:www-data','/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3')
 wp('plugin','activate','ozeki-database-backup-for-s3')
 if exact:
  with zipfile.ZipFile(archive) as z:
   expected={n.split('/',1)[1]:hashlib.sha256(z.read(n)).hexdigest() for n in z.namelist() if not n.endswith('/')}
  actual=json.loads(wp('eval',"$base=WP_PLUGIN_DIR.'/ozeki-database-backup-for-s3/';$out=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())$out[substr($f->getPathname(),strlen($base))]=hash_file('sha256',$f->getPathname());}echo json_encode($out);"))
  assert actual==expected,'Installed language-test files differ from ZIP'
 version=wp('core','version')
 # Obtain only the official WordPress core language pack; no AWS connectivity.
 translation=urllib.request.urlopen('https://downloads.wordpress.org/translation/core/'+version+'/ja.zip',timeout=60).read()
 (base/'core-ja.zip').write_bytes(translation)
 lang=base/(name+'-core-ja');lang.mkdir()
 with zipfile.ZipFile(base/'core-ja.zip') as z:z.extractall(lang)
 docker('exec',web,'mkdir','-p','/var/www/html/wp-content/languages')
 docker('cp',str(lang)+'/.',web+':/var/www/html/wp-content/languages/')
 wp('user','create','english','english@example.invalid','--role=administrator','--user_pass=fixture-only-long-password')
 wp('user','create','japanese','japanese@example.invalid','--role=administrator','--user_pass=fixture-only-long-password')
 users={name:wp('user','get',name,'--field=ID') for name in ['operator','english','japanese']}
 wp('user','meta','update',users['english'],'locale','en_US');wp('user','meta','update',users['japanese'],'locale','ja')
 def request(user,path,data=None):
  args=['exec',web,'curl','--fail','--silent','--show-error','--location','--max-time','30','-b','/tmp/'+user+'.cookies','-c','/tmp/'+user+'.cookies']
  if data is not None:args+=['--data',urllib.parse.urlencode(data)]
  return docker(*args,'http://localhost/'+path)
 for user in users:
  request(user,'wp-login.php')
  request(user,'wp-login.php',{'log':user,'pwd':'fixture-only-long-password','wp-submit':'Log In','testcookie':'1','redirect_to':'http://localhost/wp-admin/'})
 results=[]
 for site in ['en_US','ja']:
  wp('option','update','WPLANG',site)
  for user in ['operator','english','japanese']:
   expect=site if user=='operator' else ('ja' if user=='japanese' else 'en_US')
   html=request(user,'wp-admin/options-general.php?page=ozeki-database-backup-for-s3')
   (base/(site+'-'+user+'.html')).write_text(html)
   guide='getting-started-ja.md' if expect=='ja' else 'getting-started.md'
   other='getting-started.md' if expect=='ja' else 'getting-started-ja.md'
   assert guide in html and other not in html,(site,user,'guide')
   expected=['初めてのバックアップ','導入ガイドを開く','接続確認','今すぐバックアップ','最近のバックアップ','メディアのバックアップを開始','変更を保存'] if expect=='ja' else ['Your first backup','Open the setup guide','Test Connection','Backup Now','Recent Backups','Start Media Backup','Save Changes']
   for text in expected:assert text in html,(site,user,text)
   assert ('First backup / 初めて' not in html)
   forbidden='Your first backup' if expect=='ja' else '初めてのバックアップ'
   assert forbidden not in html,(site,user,'mixed language')
   results.append({'site':site,'user':user,'expected_locale':expect,'passed':True})
 # Reload both languages while interleaving users, checking no locale leaks.
 for user in ['japanese','english','japanese']:
  html=request(user,'wp-admin/options-general.php?page=ozeki-database-backup-for-s3')
  assert ('導入ガイドを開く' in html)==(user=='japanese')
 # Valid nonce, empty destination: exercise localized validation notices without AWS.
 for user, expected in [('english','Region and bucket are required.'),('japanese','リージョンとバケットを入力してください。')]:
  html=request(user,'wp-admin/options-general.php?page=ozeki-database-backup-for-s3')
  nonce=re.search(r'name="secure_s3_storage_test_nonce" value="([^"]+)"',html)[1]
  html=request(user,'wp-admin/admin-post.php',{'action':'secure_s3_storage_test_connection','secure_s3_storage_test_nonce':nonce})
  assert expected in html,(user,'connection validation notice')
 assert wp('eval',"echo json_encode(get_option('secure_s3_storage_settings', []));")=='[]','Display/validation changed saved settings'
 result={'passed':True,'wordpress':version,'php':wp('eval','echo PHP_VERSION;'),'cases':results,'authenticated_admin_http':True,'interleaved_users':True,'connection_validation_notices':['en_US','ja'],'saved_settings_unchanged':True,'aws_requests':False,'published_zip_unchanged':True}
 result.update({'exact_zip':exact,'zip_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'previous_zip':pathlib.Path(previous).name if previous else None,'plugin_version':wp('plugin','get','ozeki-database-backup-for-s3','--field=version')})
 if os.environ.get('ODBFS3_TEST_LOADER_CONTROL')=='1':
  # Disposable negative control for Plugin Check's textdomain warning.
  # Leave the ZIP untouched; restore the installed entrypoint in finally.
  entry=stage/'ozeki-database-backup-for-s3/ozeki-database-backup-for-s3.php'
  original=entry.read_text();assert original.count('load_plugin_textdomain(')==1
  disabled=base/(name+'-loader-disabled.php')
  disabled.write_text(original.replace('load_plugin_textdomain(', 'array(', 1))
  destination=web+':/var/www/html/wp-content/plugins/ozeki-database-backup-for-s3/ozeki-database-backup-for-s3.php'
  try:
   docker('cp',str(disabled),destination)
   # Restart this disposable web container to rule out cached PHP bytecode.
   docker('restart',web);time.sleep(3)
   control=request('japanese','wp-admin/options-general.php?page=ozeki-database-backup-for-s3')
   result['loader_control_without_registration_japanese']='導入ガイドを開く' in control
  finally:
   docker('cp',str(entry),destination);docker('restart',web);time.sleep(3)
  restored=request('japanese','wp-admin/options-general.php?page=ozeki-database-backup-for-s3')
  assert '導入ガイドを開く' in restored,'Original loader did not restore Japanese UI'
  result['loader_control_original_restored']=True
 (base/'result.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result,indent=2))
finally:
 for kind,item in reversed(created):
  try:docker(kind,'rm',*(['-f','-v'] if kind=='container' else []),item)
  except Exception as e:print('Cleanup required for '+item+': '+str(e))
