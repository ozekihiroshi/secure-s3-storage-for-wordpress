<?php
// Only the disposable CLI runner mounts /inspection. Setup is separate from dry-run.
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1' || !is_dir('/inspection'))throw new RuntimeException('Fixture required');
$id=$args[0]??'';$mode=$args[1]??'';
if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid fixture');
$path='/inspection/recovery-'.$id;
if($mode==='setup'){
    chmod('/inspection',0700);
    $uploads=wp_upload_dir();if($uploads['error'])throw new RuntimeException('Fixture uploads');
    $r=(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryCollector(
        \SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecovery($GLOBALS['wpdb'],$id)))->current();
    $set=\SecureS3StorageForWordpress\Backup\Recovery\RecoverySet::fromIntent($r['intent']);
    $work=new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryWorkspace('/inspection',$set,ABSPATH,$uploads['basedir']);
    $work->acquire();$work->release();
    foreach(['database.sql'=>'synthetic sql','database.sql.gz'=>'synthetic gzip']as $name=>$data){file_put_contents($path.'/'.$name,$data);chmod($path.'/'.$name,0600);}
}elseif($mode==='prepare-execution'){
    $store=\SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecovery($GLOBALS['wpdb'],$id);
    $before=$store->read();$r=(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryCollector($store))->current();
    $r['database']['raw_sql']=['size_bytes'=>filesize($path.'/database.sql'),'sha256'=>hash_file('sha256',$path.'/database.sql')];
    $r['database']['artifact']['size_bytes']=filesize($path.'/database.sql.gz');$r['database']['artifact']['sha256']=hash_file('sha256',$path.'/database.sql.gz');
    $r['manifest']=\SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest::assemble(
        \SecureS3StorageForWordpress\Backup\Recovery\RecoverySet::fromIntent($r['intent']),$r['environment'],$r['database'],$r['media'])->encode();
    $r['checkpoint']['sha256']=hash('sha256',$r['manifest']);$r['checkpoint']['size_bytes']=strlen($r['manifest']);
    if(!$store->compareAndSwap($before,json_encode($r,JSON_THROW_ON_ERROR)))throw new RuntimeException('Fixture CAS failed');
}elseif(!in_array($mode,['snapshot','evidence'],true)){throw new RuntimeException('Invalid mode');}
$files=[];
foreach(scandir($path)as $name){if($name==='.'||$name==='..')continue;
    if($mode==='evidence' && in_array($name,['database.sql','database.sql.gz'],true))continue;
    $s=lstat($path.'/'.$name);$files[$name]=[hash_file('sha256',$path.'/'.$name),$s['ino'],$s['size'],$s['mtime'],$s['mode']];
}
echo hash('sha256',json_encode($files));
