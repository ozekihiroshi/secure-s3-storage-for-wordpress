<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-catalog.php';
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryCleanup,RecoveryRecordSnapshot};
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;
$initial=$checks;$bytes=json_encode($completeRecord);$s=new RecoveryRecordSnapshot($bytes);
$summary=static fn($journal)=>(new RecoveryCleanup($s,new RecoveryRecordSnapshot($journal)))->status($set->id);
$check($summary(null)['state']==='not_started','missing journal not started');
$check($summary('{')['state']==='unknown','corrupt journal unknown');
$r=['schema'=>1,'binding'=>['set_id'=>$set->id,'collector_sha256'=>hash('sha256',$bytes),'parent'=>'/not-present/SECRET',
    'directory'=>['dev'=>1,'ino'=>2,'mode'=>16832,'uid'=>1,'gid'=>1],'manifest_sha256'=>hash('sha256',$completeRecord['manifest'])],'files'=>[]];
foreach(['database.sql','database.sql.gz'] as $name){$e=$name==='database.sql'?$completeRecord['database']['raw_sql']:$completeRecord['database']['artifact'];
    $r['files'][$name]=['state'=>'prepared','identity'=>['dev'=>1,'ino'=>3,'mode'=>33152,'nlink'=>1,'uid'=>1,'gid'=>1,'size'=>$e['size_bytes'],'mtime'=>1,'ctime'=>1], 'sha256'=>$e['sha256'],'size_bytes'=>$e['size_bytes']];}
$check($summary(json_encode($r))['state']==='pending','prepared pending without workspace access');
$r['files']['database.sql']['state']='deleting';$check($summary(json_encode($r))['state']==='needs_reconciliation','deleting is not asserted running');
$r['files']['database.sql']['state']='absent_after_intent';$r['files']['database.sql.gz']['state']='deleted';
$out=$summary(json_encode($r));$check($out['state']==='complete' && !str_contains(json_encode($out),'SECRET'),'complete summary excludes internal path');
$invalid=$r;$invalid['files']['database.sql']['identity']['mode']=0;$check($summary(json_encode($invalid))['state']==='unknown','invalid metadata cannot display cleanup complete');
$r['binding']['collector_sha256']=str_repeat('0',64);$check($summary(json_encode($r))['state']==='unknown','journal wrong binding unknown');
$db=new CatalogDb();$db->data=['odbfs3_recovery_'.$set->id=>$bytes];$cat=new WordPressRecoveryCatalog($db);$before=$db->data;
$list=$cat->inventory();$check($list['complete'] && count($list['sets'])===1,'valid inventory');
$check($db->data===$before && $db->writes===0,'inventory read only');
$db->data['odbfs3_recovery_'.str_repeat('e',32)]='SECRET';$list=$cat->inventory();
$check(!$list['complete'] && $list['invalid_records']===1 && !str_contains(json_encode($list),'SECRET'),'corrupt record surfaced without content');
for($i=0;$i<101;$i++)$db->data['odbfs3_recovery_'.str_pad(dechex($i),32,'0',STR_PAD_LEFT)]='broken';
$check($cat->inventory()['truncated'],'bounded inventory');
echo 'PASS admin read models checks='.($checks-$initial)."\n";
