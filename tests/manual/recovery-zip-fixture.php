<?php
if(getenv('ODBFS3_STATUS_FIXTURE')!=='1')throw new RuntimeException('Disposable fixture required');
$mode=$args[0];$db=$GLOBALS['wpdb'];
if($mode==='legacy'){
 update_option('secure_s3_storage_settings',['region'=>'ap-northeast-1','bucket'=>'fixture-never-contact','prefix'=>'generated-only','backup_schedule'=>'daily','retention_keep_count'=>7],false);
 $entry=new \SecureS3StorageForWordpress\Backup\History\BackupHistoryEntry(true,new DateTimeImmutable('2026-10-01T00:00:00Z'),'fixture','php',bucket:'fixture-never-contact',key:'generated-only/old.sql.gz',sizeBytes:100,message:'Synthetic history fixture',sha256:str_repeat('a',64),verification:'remote_integrity');
 update_option('secure_s3_storage_backup_history',[$entry->toArray()],false);
 wp_schedule_event(time()+86400,'daily','secure_s3_storage_database_backup_cron');
}elseif($mode==='extended'){
 $id=$args[1];$bytes=\SecureS3StorageForWordpress\WordPress\WordPressJobStore::forRecovery($db,$id)->read();$r=json_decode($bytes,true);
 $manifest=\SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest::decode($r['manifest']);
 $run=new \SecureS3StorageForWordpress\Backup\Recovery\ObservedRecoveryRun(
  \SecureS3StorageForWordpress\WordPress\WordPressJobStore::forObservationRun($db,str_repeat('9',32)), $manifest,'availability',null,
  static function(){throw new RuntimeException('Fixture pending delivery');});
 $result=$run->execute(static function(){});
 if($result['registration']!=='pending')throw new RuntimeException('Expected pending delivery');
 $i=$r['intent'];$config=['source_id'=>str_repeat('8',32),'profile'=>$i['profile'],'bucket'=>$i['bucket'],'region'=>$i['region'],'prefix'=>$i['prefix'],'backend'=>'native','environment'=>$r['environment']];
 $scheduler=\SecureS3StorageForWordpress\WordPress\WordPressRecoverySchedule::create($db,$config,static function(){throw new RuntimeException('Fixture stopped before acquisition');});
 if($scheduler->handle()['phase']!=='unknown')throw new RuntimeException('Expected preserved incomplete schedule');
}elseif($mode==='view'){
 wp_set_current_user(get_user_by('login','fixture')->ID);$_GET=['set_id'=>$args[1]];
 ob_start();(new \SecureS3StorageForWordpress\Admin\RecoverySetsPage())->render();$html=ob_get_clean();
 foreach(['Manifest SHA-256','needs_reconciliation','available','failed'] as $word)if(!str_contains($html,$word))throw new RuntimeException('Missing rendered state '.$word);
 if(str_contains($html,'SECRET'))throw new RuntimeException('Leaked path');
 // Exercise malformed GET shapes and XSS input against the installed ZIP renderer.
 foreach ([['set_id'=>[$args[1]]],['set_id'=>'<script>alert(1)</script>'],['source_id'=>['a']],['source_id'=>str_repeat('a',32).'<b>']] as $query) {
  $_GET=$query;ob_start();(new \SecureS3StorageForWordpress\Admin\RecoverySetsPage())->render();$bad=ob_get_clean();
  if(!str_contains($bad,'32桁の小文字16進数') || str_contains($bad,'<script>'))throw new RuntimeException('Malformed filter not safely rejected');
 }
 $_GET=['source_id'=>str_repeat('a',32),'schedule_saved'=>'1'];
 ob_start();(new \SecureS3StorageForWordpress\Admin\RecoverySetsPage())->render();$form=ob_get_clean();
 foreach(['設定を保存しました','name="_wpnonce"','name="source_id"','name="expected"'] as $word)
  if(!str_contains($form,$word))throw new RuntimeException('Buffered form lost '.$word);
}else{throw new RuntimeException('Unknown fixture action');}
echo 'PASS '.$mode;
