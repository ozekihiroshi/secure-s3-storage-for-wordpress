<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;
$id=$args[0];$db=$GLOBALS['wpdb'];
// Fixture-only pending cleanup journal, deliberately referring to a nonexistent path.
$bytes=WordPressJobStore::forRecovery($db,$id)->read();$r=json_decode($bytes,true);$journal=['schema'=>1,'binding'=>['set_id'=>$id,'collector_sha256'=>hash('sha256',$bytes),'parent'=>'/does-not-exist/SECRET',
    'directory'=>['dev'=>1,'ino'=>2,'mode'=>16832,'uid'=>1,'gid'=>1],'manifest_sha256'=>hash('sha256',$r['manifest'])],'files'=>[]];
foreach(['database.sql','database.sql.gz']as $name){$e=$name==='database.sql'?$r['database']['raw_sql']:$r['database']['artifact'];
    $journal['files'][$name]=['state'=>'deleting','identity'=>['dev'=>1,'ino'=>3,'mode'=>33152,'nlink'=>1,'uid'=>1,'gid'=>1,'size'=>$e['size_bytes'],'mtime'=>1,'ctime'=>1],'sha256'=>$e['sha256'],'size_bytes'=>$e['size_bytes']];}
update_option('odbfs3_cleanup_'.$id,wp_json_encode($journal),false);


