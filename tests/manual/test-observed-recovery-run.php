<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-catalog.php';
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\{ObservedRecoveryRun,RecoveryManifest};
final class ObservationMemoryJournal implements JobStore {
    public ?string $bytes=null;
    public function read():?string{return $this->bytes;}
    public function compareAndSwap(?string $expected,string $replacement):bool {
        if($this->bytes!==$expected)return false;$this->bytes=$replacement;return true;
    }
}
$initial=$checks;$m=RecoveryManifest::decode($completeRecord['manifest']);
$clock=static fn():string=>'2026-10-19T00:00:00Z';$env=str_repeat('b',32);
$journal=new ObservationMemoryJournal();$deliveries=[];$offline=true;$calls=0;
$sink=static function(array $r)use(&$offline,&$deliveries):string {
    $bytes=json_encode($r);$hash=hash('sha256',$bytes);$deliveries[$hash]=$r;
    if($offline)throw new RuntimeException('SECRET registration acknowledgement lost');return $hash;
};
$operation=static function(ObservedRecoveryRun $run)use(&$calls):void{
    ++$calls;foreach(['sql_import','wordpress_content','media_hashes','attachment_references'] as $name)$run->check($name,static fn():bool=>true);
};
$run=new ObservedRecoveryRun($journal,$m,'restore',$env,$sink,$clock);
$result=$run->execute($operation);
$check($result===['operation_passed'=>true,'registration'=>'pending'],'registration failure not hidden');
$check(!str_contains($journal->bytes,'SECRET') && json_decode($journal->bytes,true)['phase']==='delivery_pending','stable durable outbox');
$reject(fn()=>$run->execute($operation),'no repeat execution');
$offline=false;$retry=new ObservedRecoveryRun($journal,$m,'restore',$env,$sink,$clock);
$check($retry->deliver()===['operation_passed'=>true,'registration'=>'registered'] && $calls===1 && count($deliveries)===1,'lost ACK retry delivery only');
$before=$journal->bytes;$retry->deliver();$check($before===$journal->bytes && $calls===1,'registered replay no side effects');
foreach(['throw','false','swallowed','incomplete'] as $case){
    $j=new ObservationMemoryJournal();$seen=null;
    $r=new ObservedRecoveryRun($j,$m,'restore',$env,static function(array $ob)use(&$seen):string{$seen=$ob;return hash('sha256',json_encode($ob));},$clock);
    $out=$r->execute(static function(ObservedRecoveryRun $run)use($case):void{
        $run->check('sql_import',static fn():bool=>true);
        if($case==='throw')throw new RuntimeException('SECRET database');
        if($case==='incomplete')return;
        try{$run->check('media_hashes',static fn():bool=>false);}catch(Throwable $e){if($case!=='swallowed')throw $e;}
    });
    $check(!$out['operation_passed'] && $seen['state']==='failed' && $seen['checks']===['sql_import'],'failed '.$case.' never passed');
    $check(!str_contains($j->bytes,'SECRET'),'no raw failure details');
}
$j=new ObservationMemoryJournal();$seen=null;
$r=new ObservedRecoveryRun($j,$m,'availability',null,static function(array $ob)use(&$seen):string{$seen=$ob;return hash('sha256',json_encode($ob));},$clock);
$r->execute(static function(){throw new RuntimeException('network error');});
$check($seen['state']==='unknown','transport error not assumed missing');
$running=json_decode($journal->bytes,true);$running['phase']='running';$j->bytes=json_encode($running);
$reject(fn()=>$r->deliver(),'interrupted execution cannot register success');
$foreign=new ObservedRecoveryRun($journal,$m,'restore',str_repeat('f',32),$sink,$clock);
$reject(fn()=>$foreign->deliver(),'wrong environment cannot deliver');
if(!function_exists('wp_cache_delete')){function wp_cache_delete($key,$group){return true;}}
class ObserverDb extends CatalogDb {
    public function query($q){
        if(str_starts_with($q[0],'UPDATE')){
            ++$this->writes;[$bytes,$name,$expected]=$q[1];
            if(($this->data[$name]??null)!==$expected)return 0;
            $this->data[$name]=$bytes;return 1;
        }
        return parent::query($q);
    }
}
$odb=new ObserverDb();$original=json_encode($completeRecord);$odb->data['odbfs3_recovery_'.$set->id]=$original;
$rid=str_repeat('c',32);
$wrapper=SecureS3StorageForWordpress\WordPress\WordPressRecoveryObserver::forSet($odb,$set->id,$rid,'restore',$env);
$check($odb->writes===0,'wrapper construction only reads');
$out=$wrapper->execute($operation);
$check($out===['operation_passed'=>true,'registration'=>'registered'],'WordPress wrapper registers automatically');
$check($odb->data['odbfs3_recovery_'.$set->id]===$original && isset($odb->data['odbfs3_observer_run_'.$rid]),'separate durable execution slot');
$retry=SecureS3StorageForWordpress\WordPress\WordPressRecoveryObserver::forSet($odb,$set->id,$rid,'restore',$env);
$writes=$odb->writes;$retry->deliver();$check($odb->writes===$writes,'WordPress registered replay no writes');
echo json_encode(['kind'=>'observed_recovery_run_contract','passed'=>true,'checks'=>$checks-$initial]),"\n";
