#!/usr/bin/env python3
"""Dedicated external crond lab, no HTTP server or site visits."""
import hashlib,json,os,pathlib,secrets,subprocess,sys,tarfile,time,zipfile
root=pathlib.Path(sys.argv[1]).resolve()
assert root.parent==pathlib.Path('/tmp') and root.name.startswith('odbfs3-direct.')
assert not root.is_symlink()
run=secrets.token_hex(6)
runtime=root/'ozeki-database-backup-for-s3'
archive=root/'candidate.zip'
assert hashlib.sha256(archive.read_bytes()).hexdigest()=='34315c718ac34a970f64dcc5353c1208ec3b40c272be803bdfae127217200d43'
with zipfile.ZipFile(archive) as z:
    assert all(not n.startswith('/') and '..' not in pathlib.PurePosixPath(n).parts for n in z.namelist())
    z.extractall(root)
with tarfile.open(root/'helpers.tar.gz') as t:
    assert all(not n.name.startswith('/') and '..' not in pathlib.PurePosixPath(n.name).parts and n.isfile() for n in t.getmembers())
    t.extractall(runtime)
files={str(p.relative_to(runtime)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ('src','vendor') for p in (runtime/d).rglob('*') if p.is_file()}
(root/'runtime-hashes.json').write_text(json.dumps(files,sort_keys=True))
containers=[];networks=[]
def call(args,quiet=False):
    p=subprocess.run(args,stdout=subprocess.PIPE if quiet else None,stderr=subprocess.PIPE if quiet else None,text=True)
    if p.returncode: raise RuntimeError('Command failed: '+args[0]+' '+str(p.returncode))
    return p.stdout.strip() if quiet else None
def container(name,args,quiet=False):
    containers.append(name)
    return call(['docker','run','--name',name,'--label','odbfs3.test=direct-s3','--pull=never']+args,quiet)
def dbstart(name,network,password,source):
    args=['-d','--network',network,'--network-alias','fixture-db','--memory=192m','--cpus=0.35','--tmpfs','/var/lib/mysql','--tmpfs','/run/mysqld']
    if source: args+=['-p','127.0.0.1::3306']
    args+=['-e','MARIADB_RANDOM_ROOT_PASSWORD=1','-e','MARIADB_DATABASE=fixture','-e','MARIADB_USER=fixture','-e','MARIADB_PASSWORD='+password,'mariadb:11.8','--innodb-buffer-pool-size=32M','--max-connections=10','--performance-schema=OFF']
    container(name,args,True)
    for _ in range(90):
        p=subprocess.run(['docker','exec','-e','MYSQL_PWD='+password,name,'mariadb','-h127.0.0.1','-ufixture','fixture','-e','SELECT 1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if p.returncode==0:return
        time.sleep(1)
    raise RuntimeError('Dedicated DB readiness timeout')
def worker(name,network,mounts,env,entry,args):
    command=['--rm','--network',network,'--memory=256m','--cpus=0.4','--user',str(os.getuid())+':'+str(os.getgid()),'--cap-drop=ALL','--security-opt','no-new-privileges','-v',str(runtime)+':/repo:ro']
    for host,target,mode in mounts:command+=['-v',str(host)+':'+target+':'+mode]
    for key,value in env.items():command+=['-e',key+'='+value]
    command+=['--entrypoint',entry,'wordpress:cli-php8.3']+args
    container(name,command)
def core(site,name):
    container(name,['--rm','--network','none','--memory=96m','--cpus=0.3','--user',str(os.getuid())+':'+str(os.getgid()),'-v',str(site)+':/site','--entrypoint','sh','wordpress:php8.3-apache','-c','cp -r /usr/src/wordpress/. /site/'])

def args_for(base,env):
    args=['-d','--network','host','--memory=256m','--cpus=0.4','-v',str(runtime)+':/repo:ro']
    for folder,target in [('source-site','site'),('source-private','private'),('source-cache','remote'),('expected','expected')]:args+=['-v',str(base/folder)+':/'+target]
    for key,value in env.items():args+=['-e',key+'='+value]
    return args
def start_crond(name,base,env):
    init='adduser -D -u '+str(os.getuid())+' fixture; mkdir /tmp/fixture-cron; printf "%s\\n" "* * * * * /usr/bin/flock -n /private/external-cron.lock /bin/sh /repo/tools/test-wordpress-external-cron-pulse.sh >> /private/external-launch.log 2>&1" > /tmp/fixture-cron/fixture; exec crond -f -c /tmp/fixture-cron -l 8'
    container(name,args_for(base,env)+['--user','0:0','--entrypoint','sh','wordpress:cli-php8.3','-ec',init],True)
def status(observer):return json.loads(call(['docker','exec',observer,'php','/repo/tools/test-wordpress-auto-cron-status.php'],True))
def snapshot(base,label,value):
    hashes={str(p.relative_to(base/'source-private')):hashlib.sha256(p.read_bytes()).hexdigest() for p in (base/'source-private').glob('recovery-*/*') if p.is_file()}
    result={'status':value,'workspace_hashes':hashes}
    (base/'expected'/('snapshot-'+label+'.json')).write_text(json.dumps(result));return result
def rows(base,name):
    p=base/'source-private'/name
    return [json.loads(x) for x in p.read_text().splitlines()] if p.exists() else []
def wait_until(at):
    while time.time()<at:time.sleep(min(2,at-time.time()))
def missed(base,slot,label):
    wait_until(slot+5)
    starts=rows(base,'external-cron.jsonl')
    assert not any(x['event']=='pulse_start' and slot<=x['at']<slot+60 for x in starts)
    evidence={'event':'scheduled_start_missing','slot_utc_epoch':slot,'observed_at':time.time(),'scenario':label,'detector':'independent lab controller; not a production monitor'}
    with (base/'expected/missing-starts.jsonl').open('a') as f:f.write(json.dumps(evidence)+'\n')
    print('PASS missed external Cron start detected scenario='+label,flush=True)
try:
    backend='php';base=root/backend;base.mkdir(mode=0o700)
    for name in ('source-site','source-private','source-cache','expected','download','download-private'):(base/name).mkdir(mode=0o700)
    prefix='wordpress-test/direct-worker/'+run+'/'+backend
    stem='odbfs3-direct-'+run+'-'+backend;network=stem+'-net';networks.append(network)
    call(['docker','network','create','--label','odbfs3.test=direct-s3',network],True)
    password=secrets.token_hex(24);db=stem+'-db';dbstart(db,network,password,True)
    dbport=call(['docker','port',db,'3306'],True).split(':')[-1]
    core(base/'source-site',stem+'-core')
    env={'ODBFS3_WP_FIXTURE':'1','ODBFS3_DIRECT_S3':'1','ODBFS3_AUTO_CRON':'1','ODBFS3_EXTERNAL_CRON':'1','FIXTURE_BACKEND':backend,'FIXTURE_PASSWORD':password,'FIXTURE_DB_HOST':'127.0.0.1:'+dbport,'FIXTURE_DB_PORT':dbport,'FIXTURE_S3_PREFIX':prefix,'FIXTURE_HTTP_URL':'http://127.0.0.1:1'}
    mounts=[(base/'source-site','/site','rw'),(base/'source-private','/private','rw'),(base/'source-cache','/remote','rw'),(base/'expected','/expected','rw')]
    worker(stem+'-setup','host',mounts,env,'php',['/repo/tools/test-wordpress-direct-s3-worker.php','cronsetup'])
    observer=stem+'-observer';container(observer,args_for(base,env)+['--user',str(os.getuid())+':'+str(os.getgid()),'--entrypoint','sleep','wordpress:cli-php8.3','infinity'],True)
    assert status(observer)['collector'] is None
    first_slot=(int(time.time())//60+1)*60
    (base/'expected/plan.json').write_text(json.dumps({'first_expected_slot':first_slot,'period_seconds':60,'late_grace_seconds':5,'no_http_server':True,'visitor_requests':0}))
    missed(base,first_slot,'scheduler_not_started')
    assert status(observer)['collector'] is None
    (base/'source-private/stop-after-db').write_text('Controlled stop after acknowledged DB save')
    daemon=stem+'-crond';start_crond(daemon,base,env)
    for _ in range(85):
        if (base/'source-private/gate-reached.json').exists():break
        time.sleep(2)
    assert (base/'source-private/gate-reached.json').exists(),'crond did not reach DB save gate'
    before=snapshot(base,'before-stop',status(observer));record=json.loads(before['status']['collector'])
    assert record['phase']=='database' and record['until']>time.time()
    call(['docker','kill',daemon],True);call(['docker','rm',daemon],True)
    (base/'source-private/stop-after-db').unlink()
    stopped_slot=(int(time.time())//60+1)*60
    missed(base,stopped_slot,'scheduler_and_worker_stopped')
    stopped=snapshot(base,'while-stopped',status(observer))
    assert stopped['status']['collector']==before['status']['collector'] and stopped['workspace_hashes']==before['workspace_hashes']
    assert not any(x.get('result',{}).get('phase')=='complete' for x in rows(base,'cron-events.jsonl'))
    print('PASS stopped state preserved; no fabricated success or backfill',flush=True)
    daemon=stem+'-crond-resumed';start_crond(daemon,base,env)
    resumed=snapshot(base,'after-restart',status(observer))
    assert resumed['status']['collector']==before['status']['collector']
    for _ in range(150):
        time.sleep(3);state=status(observer);r=json.loads(state['collector'])
        assert r['intent']==record['intent'] and r['phase']!='failed'
        if r['phase']=='complete':break
    assert r['phase']=='complete','External Cron completion deadline'
    complete=snapshot(base,'complete',state)
    for name,digest in before['workspace_hashes'].items():
        if name.endswith(('/database.sql','/database.sql.gz')):assert complete['workspace_hashes'][name]==digest
    (base/'expected/collector.json').write_text(state['collector']);data=r['manifest'].encode()
    (base/'expected/locator.json').write_text(json.dumps({'bucket':r['intent']['bucket'],'key':r['intent']['prefix']+'/backups/recovery-sets/'+r['intent']['id']+'/manifest.json','size':len(data),'sha256':hashlib.sha256(data).hexdigest()}))
    for _ in range(20):
        if any(x.get('result',{}).get('reason_code')=='already_complete' for x in rows(base,'cron-events.jsonl')):break
        time.sleep(3)
    call(['docker','kill',daemon],True);call(['docker','rm',daemon],True)
    daemon=stem+'-crond-completed';start_crond(daemon,base,env)
    previous=len(rows(base,'cron-events.jsonl'))
    for _ in range(45):
        time.sleep(2);new=rows(base,'cron-events.jsonl')[previous:]
        if any(x.get('result',{}).get('reason_code')=='already_complete' for x in new):break
    assert any(x.get('result',{}).get('reason_code')=='already_complete' for x in new)
    final=snapshot(base,'completed-restart',status(observer))
    assert final['status']['collector']==state['collector'] and final['workspace_hashes']==complete['workspace_hashes']
    call(['docker','kill',daemon],True);call(['docker','rm',daemon],True)
    for name in ('external-cron.jsonl','cron-events.jsonl','external-cron-worker.log','external-launch.log'):
        p=base/'source-private'/name
        if p.exists():(base/'expected'/name).write_bytes(p.read_bytes())
    callbacks=rows(base,'cron-events.jsonl');assert all(x['doing_cron'] for x in callbacks)
    assert not any(x['event']=='entry_failed' for x in rows(base,'external-cron.jsonl'))
    (base/'expected/external-result.json').write_text(json.dumps({'run':run,'backend':backend,'visitor_requests':0,'http_server_started':False,'disable_wp_cron':True,'scheduler':'BusyBox crond; 60-second cron; bounded 45-second pulse','missed_slots_detected':2,'state_preserved_during_stop':True,'same_set_resumed':True,'sql_gzip_preserved':True,'completed_restart_preserved':True,'set_id':r['intent']['id'],'checked_at':time.time()}))
    call(['docker','rm','-f',observer],True);call(['docker','rm','-f',db],True)
    worker(stem+'-download','host',[(base/'download','/remote','rw'),(base/'download-private','/private','rw'),(base/'expected','/expected','rw')],{'ODBFS3_DIRECT_S3':'1','FIXTURE_S3_PREFIX':prefix},'php',['/repo/tools/test-wordpress-direct-s3-download.php'])
    assert files=={str(p.relative_to(runtime)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ('src','vendor') for p in (runtime/d).rglob('*') if p.is_file()}
    with tarfile.open(root/'evidence.tar.gz','w:gz') as t:
        t.add(root/'runtime-hashes.json',arcname='runtime-hashes.json')
        for folder in ('expected','download'):t.add(base/folder,arcname=backend+'/'+folder)
    print('PASS external crond no-traffic S3 and missed-start/stop/resume run='+run,flush=True)
finally:
    for name in containers:
        log=subprocess.run(['docker','logs',name],capture_output=True,text=True)
        if 'crond' in name:(root/(name+'-daemon.log')).write_text(log.stderr)
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for name in networks:subprocess.run(['docker','network','rm',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
