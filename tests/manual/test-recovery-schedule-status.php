<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-execution.php';
use SecureS3StorageForWordpress\Cron\{RecoverySchedulePolicy,RecoveryScheduleStatus,RecoveryExecutionRecord};
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryRecordSnapshot;
use SecureS3StorageForWordpress\Backup\Job\JobStore;

$initial=$checks;$source=$set->sourceId;$base=strtotime($set->createdAt);
$ps=new CronMemoryStore();$es=new CronMemoryStore();$cs=new CronMemoryStore();
$policy=new RecoverySchedulePolicy($ps,$source);$execution=new RecoveryExecutionRecord($es,$source);
$view=new RecoveryScheduleStatus($ps,$es,static fn($id)=>$cs,$source);
$settings=['mode'=>'enabled','anchor_at'=>$base,'interval_seconds'=>86400,'start_grace_seconds'=>60,'completion_grace_seconds'=>300];
$check($view->read($base)['state']==='not_configured','missing policy is not healthy');
$p=$policy->replace(null,$settings,$base-100);
$before=$ps->bytes;$policy->replace($p,$settings,$base-90);$check($ps->bytes===$before,'same settings do not rewrite');
$check($view->read($base-1)['state']==='not_due','future first slot');
$check($view->read($base)['state']==='within_grace','first due with no invocation');
$check($view->read($base+60)['start']['state']==='within_grace','exact start deadline inclusive');
$r=$view->read($base+61);$check($r['state']==='overdue' && $r['start']['overdue_seconds']===1 && $r['completion']['state']==='within_grace','start overdue separate from completion');
$check($view->read($base+300)['completion']['state']==='within_grace','exact completion deadline inclusive');
$check($view->read($base+301)['completion']['overdue_seconds']===1,'completion overdue');
$run=$execution->start($base+100);
$r=$view->read($base+101);$check($r['start']['state']==='observed' && $r['completion']['state']==='within_grace','unfinished invocation does not imply completion');
$result=['schema_version'=>1,'set_id'=>$set->id,'phase'=>'failed','reason_code'=>'operator_review_required'];
$execution->finish($run,$result,$base+102,null,null);
$r=$view->read($base+301);$check($r['start']['state']==='observed' && $r['completion']['state']==='overdue','failed attempt does not satisfy completion');
$check($view->read($base+99)['state']==='unknown','future invocation cannot imply healthy');
$cs->bytes=json_encode($completeRecord);
$successAt=strtotime($completeRecord['checkpoint']['verified_at']);
$es->bytes=null;$execution=new RecoveryExecutionRecord($es,$source);
$run=$execution->start($base);
$execution->finish($run,['schema_version'=>1,'set_id'=>$set->id,'phase'=>'complete','reason_code'=>'publication_verified'],
    $successAt,$set->id,['set_id'=>$set->id,'at'=>$completeRecord['checkpoint']['verified_at']]);
$r=$view->read(max($base+301,$successAt));
$check(in_array($r['state'],['complete','complete_late'],true) && $r['completion']['observed_at']===$completeRecord['checkpoint']['verified_at'],'validated current slot success');
$late=$settings;$late['start_grace_seconds']=0;$late['completion_grace_seconds']=0;
$p=$policy->replace($p,$late,$base-80);
$r=$view->read(max($base+301,$successAt));
$check($r['state']==='complete_late' && $r['completion']['completion_delay_seconds']===$successAt-$base,'late completion retains delay');
$p=$policy->replace($p,$settings,$base-70);
$run=$execution->start($base+86401);
$r=$view->read($base+86701);
$check($r['start']['state']==='observed' && $r['completion']['state']==='overdue','previous day complete does not satisfy current slot');
$oldCollector=$cs->bytes;$oldExecution=$es->bytes;
$lateOld=json_decode($cs->bytes,true);$lateTime=gmdate('Y-m-d\TH:i:s\Z',$base+86500);
$lateOld['checkpoint']['verified_at']=$lateTime;$cs->bytes=json_encode($lateOld);
$lateExecution=json_decode($es->bytes,true);$lateExecution['last_success']['at']=$lateTime;$es->bytes=json_encode($lateExecution);
$check($view->read($base+86701)['completion']['state']==='overdue','old capture completed today is not today backup');
$cs->bytes=$oldCollector;$es->bytes=$oldExecution;
$check($view->read($base+3*86400+301)['state']==='overdue','missed days are not invented');
$saved=$cs->bytes;$cs->bytes=null;$check($view->read($base+86701)['state']==='unknown','missing success evidence unknown');
$cs->bytes='SECRET';$check($view->read($base+86701)['state']==='unknown','corrupt success evidence unknown');$cs->bytes=$saved;
$wrong=json_decode($saved,true);$wrong['intent']['sourceId']=str_repeat('e',32);$cs->bytes=json_encode($wrong);
$check($view->read($base+86701)['state']==='unknown','foreign source success cannot satisfy policy');$cs->bytes=$saved;
$badExec=json_decode($es->bytes,true);$badExec['last_success']['at']=gmdate('Y-m-d\TH:i:s\Z',$successAt+1);
$original=$es->bytes;$es->bytes=json_encode($badExec);
$check($view->read($base+86701)['state']==='unknown','success timestamp must match publication receipt');$es->bytes=$original;
$savedExec=$es->bytes;$es->bytes='SECRET<script>';
$check($view->read($base+86701)['state']==='unknown' && !str_contains(json_encode($view->read($base+86701)),'SECRET'),'corrupt execution redacted');
$stopped=$settings;$stopped['mode']='stopped';$p=$policy->replace($p,$stopped,$base+100);
$r=$view->read($base+86701);$check($r['state']==='stopped' && $r['start']===null,'stopped suppresses overdue even with unreadable execution');
$check($view->read($base+99)['state']==='unknown','future policy timestamp unknown');
$es->bytes=$savedExec;
$reject(static fn()=>$policy->replace($p,$settings,$base+86701),'resume requires explicit anchor');
$resume=$settings;$resume['anchor_at']=$base+90000;$p=$policy->replace($p,$resume,$base+86701);
$check($view->read($base+86701)['state']==='not_due','resume starts new future epoch');
$reject(static fn()=>$policy->replace(null,$settings,$base+86702),'stale configuration rejected');
$reject(static fn()=>$policy->replace($p,$resume,$base),'configuration clock rollback rejected');
foreach (['interval_seconds'=>0,'start_grace_seconds'=>-1,'completion_grace_seconds'=>86400,'mode'=>'wrong','anchor_at'=>'2026-10-19'] as $key=>$value) {
    $bad=$settings;$bad[$key]=$value;$reject(static fn()=>RecoverySchedulePolicy::settings($bad),'invalid setting '.$key);
}
$bad=$settings;$bad['completion_grace_seconds']=59;$reject(static fn()=>RecoverySchedulePolicy::settings($bad),'completion grace before start grace');
$snapshot=[$ps->bytes,$es->bytes,$cs->bytes];$view->read($base+91000);
$check($snapshot===[$ps->bytes,$es->bytes,$cs->bytes],'read-only assessment preserves all stores');
$ps->contend=true;$changed=$resume;$changed['mode']='stopped';
$reject(static fn()=>$policy->replace($p,$changed,$base+86702),'CAS conflict rejected');$ps->contend=false;
$saved=$ps->bytes;$ps->bytes='SECRET broken';$r=$view->read($base+91000);
$check($r['state']==='unknown' && $r['policy']===null && !str_contains(json_encode($r),'SECRET'),'invalid policy not unconfigured');$ps->bytes=$saved;
$changing=new class($saved) implements JobStore {
    private int $reads=0;
    public function __construct(private string $bytes){}
    public function read():?string{return ++$this->reads===1?$this->bytes:null;}
    public function compareAndSwap(?string $expected,string $replacement):bool{throw new RuntimeException('Unexpected write');}
};
$check((new RecoveryScheduleStatus($changing,$es,static fn($id)=>$cs,$source))->read($base+91000)['state']==='unknown','concurrent policy change is unknown');
$changingExecution=new class($es->bytes) implements JobStore {
    private int $reads=0;
    public function __construct(private string $bytes){}
    public function read():?string{return ++$this->reads===1?$this->bytes:null;}
    public function compareAndSwap(?string $expected,string $replacement):bool{throw new RuntimeException('Unexpected write');}
};
$check((new RecoveryScheduleStatus($ps,$changingExecution,static fn($id)=>$cs,$source))->read($base+91000)['state']==='unknown','concurrent execution update is unknown');
$noWrites=new RecoveryScheduleStatus(new RecoveryRecordSnapshot($ps->bytes),new RecoveryRecordSnapshot($es->bytes),static fn($id)=>new RecoveryRecordSnapshot($cs->bytes),$source);
$check($noWrites->read($base+91000)['state']==='overdue','readonly stores accepted without mutation');
echo 'PASS schedule status checks='.($checks-$initial)."\n";
