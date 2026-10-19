#!/usr/bin/env python3
"""Automatic WordPress Cron lab: generated data and existing test S3 only."""
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

import concurrent.futures,socket,urllib.request
def visit(url):
    with urllib.request.urlopen(url+'/',timeout=15) as response:assert response.status==200
def read_status(server):
    return json.loads(call(['docker','exec',server,'php','/repo/tools/test-wordpress-auto-cron-status.php'],True))
def events(base):
    p=base/'source-private/cron-events.jsonl'
    return [json.loads(x) for x in p.read_text().splitlines()] if p.exists() else []
def snapshot(base,label,status):
    hashes={str(p.relative_to(base/'source-private')):hashlib.sha256(p.read_bytes()).hexdigest() for p in (base/'source-private').glob('recovery-*/*') if p.is_file()}
    value={'status':status,'workspace_hashes':hashes}
    (base/'expected'/('snapshot-'+label+'.json')).write_text(json.dumps(value))
    return value
def start_server(name,base,env,http_port):
    args=['-d','--network','host','--memory=256m','--cpus=0.4','--user',str(os.getuid())+':'+str(os.getgid()),'--cap-drop=ALL','--security-opt','no-new-privileges','-v',str(runtime)+':/repo:ro']
    for folder,target in [('source-site','site'),('source-private','private'),('source-cache','remote'),('expected','expected')]:args+=['-v',str(base/folder)+':/'+target]
    for key,value in (env|{'PHP_CLI_SERVER_WORKERS':'4'}).items():args+=['-e',key+'='+value]
    args+=['--entrypoint','php','wordpress:cli-php8.3','-S','127.0.0.1:'+str(http_port),'-t','/site']
    container(name,args,True)
    for _ in range(30):
        try:
            with socket.create_connection(('127.0.0.1',http_port),timeout=1):return
        except OSError:time.sleep(1)
    raise RuntimeError('Fixture HTTP server not ready')
try:
    for backend in ('native','php'):
        base=root/backend;base.mkdir(mode=0o700)
        for name in ('source-site','source-private','source-cache','expected','download','download-private'):(base/name).mkdir(mode=0o700)
        prefix='wordpress-test/direct-worker/'+run+'/'+backend
        stem='odbfs3-direct-'+run+'-'+backend;network=stem+'-net';networks.append(network)
        call(['docker','network','create','--label','odbfs3.test=direct-s3',network],True)
        password=secrets.token_hex(24);db=stem+'-db';dbstart(db,network,password,True)
        dbport=call(['docker','port',db,'3306'],True).split(':')[-1]
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));http_port=sock.getsockname()[1]
        url='http://127.0.0.1:'+str(http_port)
        core(base/'source-site',stem+'-core')
        env={'ODBFS3_WP_FIXTURE':'1','ODBFS3_DIRECT_S3':'1','ODBFS3_AUTO_CRON':'1','FIXTURE_BACKEND':backend,'FIXTURE_PASSWORD':password,'FIXTURE_DB_HOST':'127.0.0.1:'+dbport,'FIXTURE_DB_PORT':dbport,'FIXTURE_S3_PREFIX':prefix,'FIXTURE_HTTP_URL':url}
        mounts=[(base/'source-site','/site','rw'),(base/'source-private','/private','rw'),(base/'source-cache','/remote','rw'),(base/'expected','/expected','rw')]
        worker(stem+'-setup','host',mounts,env,'php',['/repo/tools/test-wordpress-direct-s3-worker.php','cronsetup'])
        (base/'source-private/stop-after-db').write_text('Controlled interruption after product DB upload returns; no credential data')
        server=stem+'-http';start_server(server,base,env,http_port)
        assert read_status(server)['collector'] is None
        for _ in range(45):
            visit(url)
            if (base/'source-private/gate-reached.json').exists():break
            time.sleep(2)
        assert (base/'source-private/gate-reached.json').exists(),'Automatic Cron did not reach S3 DB upload'
        before=snapshot(base,'before-overlap',read_status(server))
        record=json.loads(before['status']['collector']);assert record['phase']=='database' and record['until']>time.time()
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(lambda _:visit(url),range(8)))
        overlap=snapshot(base,'after-overlap',read_status(server))
        assert before['status']['collector']==overlap['status']['collector'] and before['workspace_hashes']==overlap['workspace_hashes']
        assert len([e for e in events(base) if e.get('event')=='database_saved_gate'])==1
        print('PASS automatic Cron and concurrent visitor suppression backend='+backend,flush=True)
        call(['docker','kill',server],True)
        call(['docker','rm',server],True)
        (base/'source-private/stop-after-db').unlink()
        server=stem+'-http-resumed';start_server(server,base,env,http_port)
        restarted=snapshot(base,'after-restart',read_status(server))
        assert restarted['status']['collector']==before['status']['collector'] and restarted['workspace_hashes']==before['workspace_hashes']
        visit(url)
        # Wait for the REAL leases/WordPress Cron lock. Never edit clocks, transients or ledgers.
        while time.time()<record['until']+2:time.sleep(1)
        for _ in range(180):
            visit(url);time.sleep(2)
            state=read_status(server)
            r=json.loads(state['collector'])
            assert r['intent']==record['intent'] and r['phase']!='failed'
            if r['phase']=='complete':break
        assert r['phase']=='complete','Automatic Cron completion deadline'
        complete=snapshot(base,'complete',state)
        (base/'expected/collector.json').write_text(state['collector'])
        data=r['manifest'].encode()
        (base/'expected/locator.json').write_text(json.dumps({'bucket':r['intent']['bucket'],'key':r['intent']['prefix']+'/backups/recovery-sets/'+r['intent']['id']+'/manifest.json','size':len(data),'sha256':hashlib.sha256(data).hexdigest()}))
        # More due events after completion, then a second full HTTP-worker restart.
        time.sleep(3)
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(lambda _:visit(url),range(8)))
        visit(url);time.sleep(3)
        assert read_status(server)['collector']==state['collector']
        call(['docker','kill',server],True);call(['docker','rm',server],True)
        server=stem+'-http-completed';start_server(server,base,env,http_port)
        visit(url);time.sleep(3)
        final=snapshot(base,'completed-restart',read_status(server))
        assert final['status']['collector']==state['collector'] and final['workspace_hashes']==complete['workspace_hashes']
        rows=events(base)
        assert all(e['doing_cron'] for e in rows)
        assert any(e.get('result',{}).get('reason_code')=='already_complete' for e in rows)
        (base/'expected/cron-events.jsonl').write_text((base/'source-private/cron-events.jsonl').read_text())
        (base/'expected/cron-result.json').write_text(json.dumps({'run':run,'backend':backend,'set_id':r['intent']['id'],'automatic_spawn':True,'concurrent_visitors':8,'overlap_preserved_state':True,'interrupted_after_real_db_put':True,'restart_preserved_state':True,'real_lease_expiry_waited':True,'completed_restart_preserved_state':True,'all_callbacks_doing_cron':True,'no_direct_handler_calls':True,'checked_at':time.time()}))
        call(['docker','kill',server],True);call(['docker','rm',server],True)
        call(['docker','rm','-f',db],True)
        worker(stem+'-download','host',[(base/'download','/remote','rw'),(base/'download-private','/private','rw'),(base/'expected','/expected','rw')],{'ODBFS3_DIRECT_S3':'1','FIXTURE_S3_PREFIX':prefix},'php',['/repo/tools/test-wordpress-direct-s3-download.php'])
        print('PASS automatic Cron stop/resume/complete preservation and S3 download backend='+backend,flush=True)
        call(['docker','network','rm',network],True)
    assert files=={str(p.relative_to(runtime)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ('src','vendor') for p in (runtime/d).rglob('*') if p.is_file()}
    with tarfile.open(root/'evidence.tar.gz','w:gz') as t:
        t.add(root/'runtime-hashes.json',arcname='runtime-hashes.json')
        for backend in ('native','php'):
            for folder in ('expected','download'):t.add(root/backend/folder,arcname=backend+'/'+folder)
    print('PASS automatic WordPress Cron S3 run='+run,flush=True)
finally:
    for name in containers:subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for name in networks:subprocess.run(['docker','network','rm',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
