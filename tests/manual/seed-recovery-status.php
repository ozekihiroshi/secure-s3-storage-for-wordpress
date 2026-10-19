<?php
// Called only by the disposable WordPress runner, not an operator command.
if (getenv('ODBFS3_STATUS_FIXTURE') !== '1') { throw new RuntimeException('Fixture required'); }
ob_start(); require __DIR__ . '/test-recovery-contract.php'; ob_end_clean();
$record = ['schema'=>1,'intent'=>$set->toIntent(),'environment'=>$fixture['environment'],
    'phase'=>'complete','checkpoint'=>[],'database'=>$fixture['database'],'media'=>$fixture['media'],
    'manifest'=>$manifest->encode(),'lease'=>'','until'=>0,'attempts'=>0,'error'=>''];
$record['checkpoint']=['bucket'=>$set->bucket,'key'=>$set->root().'manifest.json',
    'size_bytes'=>strlen($record['manifest']),'sha256'=>hash('sha256',$record['manifest']),
    'version_id'=>null,'verified_at'=>$end,'method'=>'head_sha256_get_exact_bytes'];
update_option('odbfs3_recovery_'.$set->id,json_encode($record),false);
update_option('odbfs3_recovery_'.str_repeat('e',32),'invalid private data',false);
echo $set->id;
