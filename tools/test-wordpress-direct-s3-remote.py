#!/usr/bin/env python3
"""Dedicated EC2 lab runner: no production mounts or credentials exported."""
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
try:
    for backend in ('native','php'):
        base=root/backend;base.mkdir(mode=0o700)
        for name in ('source-site','source-private','source-cache','expected','download','download-private','restore-site','restore-private'):(base/name).mkdir(mode=0o700)
        prefix='wordpress-test/direct-worker/'+run+'/'+backend
        stem='odbfs3-direct-'+run+'-'+backend
        network=stem+'-net';networks.append(network)
        call(['docker','network','create','--label','odbfs3.test=direct-s3',network],True)
        password=secrets.token_hex(24)
        db=stem+'-source-db';dbstart(db,network,password,True)
        port=call(['docker','port',db,'3306'],True).split(':')[-1]
        core(base/'source-site',stem+'-source-core')
        env={'ODBFS3_WP_FIXTURE':'1','ODBFS3_DIRECT_S3':'1','FIXTURE_BACKEND':backend,'FIXTURE_PASSWORD':password,'FIXTURE_DB_HOST':'127.0.0.1:'+port,'FIXTURE_DB_PORT':port,'FIXTURE_S3_PREFIX':prefix,'AWS_EC2_METADATA_DISABLED':'false'}
        worker(stem+'-source','host',[(base/'source-site','/site','rw'),(base/'source-private','/private','rw'),(base/'source-cache','/remote','rw'),(base/'expected','/expected','rw')],env,'php',['/repo/tools/test-wordpress-direct-s3-worker.php','source'])
        assert json.loads((base/'expected/collector.json').read_text())['phase']=='complete'
        call(['docker','rm','-f',db],True)
        print('PASS source DB removed before independent S3 download',flush=True)
        call(['docker','network','rm',network],True)
        call(['docker','network','create','--internal','--label','odbfs3.test=direct-s3',network],True)
        worker(stem+'-download','host',[(base/'download','/remote','rw'),(base/'download-private','/private','rw'),(base/'expected','/expected','rw')],{'ODBFS3_DIRECT_S3':'1','FIXTURE_S3_PREFIX':prefix},'php',['/repo/tools/test-wordpress-direct-s3-download.php'])
        password=secrets.token_hex(24)
        db=stem+'-restore-db';dbstart(db,network,password,False)
        core(base/'restore-site',stem+'-restore-core')
        worker(stem+'-restore',network,[(base/'restore-site','/site','rw'),(base/'restore-private','/private','rw'),(base/'download','/remote','ro'),(base/'expected','/expected','ro')],{'ODBFS3_WP_FIXTURE':'1','FIXTURE_BACKEND':backend,'FIXTURE_PASSWORD':password},'php',['/repo/tools/test-wordpress-direct-s3-worker.php','restore'])
        call(['docker','rm','-f',db],True)
        call(['docker','network','rm',network],True)
    assert files=={str(p.relative_to(runtime)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ('src','vendor') for p in (runtime/d).rglob('*') if p.is_file()}
    with tarfile.open(root/'evidence.tar.gz','w:gz') as t:
        t.add(root/'runtime-hashes.json',arcname='runtime-hashes.json')
        for backend in ('native','php'):
            for folder in ('expected','download'):
                t.add(root/backend/folder,arcname=backend+'/'+folder)
            t.add(root/backend/'restore-private/restore-observation.json',arcname=backend+'/restore-observation.json')
    print('PASS direct WordPress worker S3 + isolated restore run='+run,flush=True)
finally:
    for name in containers:subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for name in networks:subprocess.run(['docker','network','rm',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
