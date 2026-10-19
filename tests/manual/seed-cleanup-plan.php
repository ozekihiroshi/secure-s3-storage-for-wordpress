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
}elseif($mode!=='snapshot'){throw new RuntimeException('Invalid mode');}
$files=[];
foreach(scandir($path)as $name){if($name==='.'||$name==='..')continue;
    $s=lstat($path.'/'.$name);$files[$name]=[hash_file('sha256',$path.'/'.$name),$s['ino'],$s['size'],$s['mtime'],$s['mode']];
}
echo hash('sha256',json_encode($files));
