<?php
if (getenv('ODBFS3_STATUS_FIXTURE')!=='1') { throw new RuntimeException('Fixture required'); }
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;
$id=$args[0];
$bytes=WordPressJobStore::forRecovery($GLOBALS['wpdb'],$id)->read();
$record=json_decode($bytes,true,24,JSON_THROW_ON_ERROR);
// Explicitly remove only the corrupt record made by this disposable fixture.
delete_option('odbfs3_recovery_'.str_repeat('e',32));
$failed=$record;$failed['intent']['id']=str_repeat('d',32);$failed['intent']['createdAt']=gmdate('Y-m-d\TH:i:s\Z');
$failed['phase']='failed';$failed['error']='collection_failed';$failed['checkpoint']=[];
$failed['database']=null;$failed['media']=null;$failed['manifest']=null;
update_option('odbfs3_recovery_'.str_repeat('d',32),json_encode($failed),false);
$ob=['set_id'=>$id,'manifest_sha256'=>hash('sha256',$record['manifest']),'kind'=>'availability',
    'state'=>'available','checked_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-10),'environment_id'=>null,
    'checks'=>['database_object','inventory_object','marker_object','all_media_objects']];
$catalog=new WordPressRecoveryCatalog($GLOBALS['wpdb']);$now=gmdate('Y-m-d\TH:i:s\Z');
$first=$catalog->appendObservation($id,$ob,$now);
if($first!==$catalog->appendObservation($id,$ob,$now))throw new RuntimeException('Duplicate append changed identity');
$rest=$ob;$rest['kind']='restore';$rest['state']='failed';$rest['environment_id']=str_repeat('b',32);$rest['checks']=[];
$catalog->appendObservation($id,$rest,$now);
if(WordPressJobStore::forRecovery($GLOBALS['wpdb'],$id)->read()!==$bytes)throw new RuntimeException('Append changed completion evidence');
echo json_encode(['source_id'=>$record['intent']['sourceId'],'set_id'=>$id]);
