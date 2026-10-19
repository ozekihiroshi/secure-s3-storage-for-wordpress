<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-status.php';
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet,RecoveryCollector,RecoveryStep,RecoveryLock,RecoveryProgress,RecoveryPublisher,RecoveryManifest};
use SecureS3StorageForWordpress\Cron\RecoverySetCronHandler;

final class CronMemoryStore implements JobStore {
    public ?string $bytes=null;
    public bool $loseAck=false;
    public bool $contend=false;
    public function read():?string{return $this->bytes;}
    public function compareAndSwap(?string $expected,string $replacement):bool {
        if($this->contend || $this->bytes!==$expected)return false;
        $this->bytes=$replacement;
        if($this->loseAck){$this->loseAck=false;throw new RuntimeException('SECRET lost ACK');}
        return true;
    }
}
final class CronStep implements RecoveryStep,RecoveryLock {
    public int $calls=0;public bool $locked=false;public bool $busy=false;public ?Closure $during=null;public bool $fail=false;
    public function acquire():bool{if($this->busy || $this->locked)return false;return $this->locked=true;}
    public function release():void{$this->locked=false;}
    public function execute(RecoverySet $s,string $phase,array $checkpoint,int $deadline):RecoveryProgress {
        ++$this->calls;if($this->during)($this->during)();if($this->fail)throw new RuntimeException('SECRET operation');
        return new RecoveryProgress(['offset'=>($checkpoint['offset']??0)+1]);
    }
}
$initial=$checks;
$config=['source_id'=>$set->sourceId,'profile'=>$set->profile,'bucket'=>$set->bucket,'region'=>$set->region,'prefix'=>$set->prefix,'backend'=>'native','environment'=>$fixture['environment']];
$now=strtotime($set->createdAt);$clock=static function()use(&$now):int{return $now;};
$gate=new CronMemoryStore();$records=[];$step=new CronStep();
$stores=static function(string $id)use(&$records):CronMemoryStore{return $records[$id]??=new CronMemoryStore();};
// Publication is covered by the actual WordPress fixture, not this step-only double.
$publisher=(new ReflectionClass(RecoveryPublisher::class))->newInstanceWithoutConstructor();
$factory=static fn()=>[$step,$publisher];
$make=static fn($c=null)=>new RecoverySetCronHandler($gate,$stores,$factory,$c??$config,$clock);
$r=$make()->handle();$id=$r['set_id'];
$check($r['phase']==='database' && count($records)===1 && $step->calls===1,'one bounded operation');
$check($make()->handle()['set_id']===$id && $step->calls===2,'repeated Cron resumes same set');
$nested=null;$step->during=static function()use(&$nested,$make){$nested=$make()->handle();};
$make()->handle();$check($nested['phase']==='busy' && $step->calls===3,'concurrent callback cannot execute while leased');$step->during=null;
$step->busy=true;$check($make()->handle()['phase']==='busy' && $step->calls===3,'workspace busy blocks execution');$step->busy=false;
$changed=$config;$changed['bucket']='different-bucket';$check($make($changed)->handle()['reason_code']==='configuration_changed' && $step->calls===3,'settings change blocked');
$now+=86400;$check($make()->handle()['set_id']===$id && count($records)===1,'next day resumes incomplete set');
$now-=172800;$check($make()->handle()['reason_code']==='clock_moved_backwards','clock rollback blocked');$now+=172800;
$saved=$records[$id]->bytes;$records[$id]->bytes=null;
$check($make()->handle()['phase']==='unknown' && $records[$id]->bytes===null,'missing active evidence not recreated');$records[$id]->bytes=$saved;
$records[$id]->bytes='{';$check($make()->handle()['phase']==='unknown','corrupt collector blocked');$records[$id]->bytes=$saved;
// Simulate process death after claim. Lease expiry resumes the same durable checkpoint.
$claimed=json_decode($saved,true);$claimed['lease']=str_repeat('b',32);$claimed['until']=$now+60;$claimed['attempts']=1;
$records[$id]->bytes=json_encode($claimed);$before=$step->calls;
$check($make()->handle()['reason_code']==='collector_lease_active' && $step->calls===$before,'killed worker lease respected');
$now+=61;$check($make()->handle()['set_id']===$id && $step->calls===$before+1,'expired lease replays same set');
$step->fail=true;$check($make()->handle()['phase']==='failed','operation failure recorded');$step->fail=false;
$before=$step->calls;$now+=86400;
$check($make()->handle()['reason_code']==='operator_review_required' && $step->calls===$before && count($records)===1,'failed not replaced next day');
$check(!str_contains($records[$id]->bytes,'SECRET'),'raw exception excluded');
// Supply independently validated complete evidence to exercise rollover, preserving history.
$r=json_decode($saved,true);$f=str_replace($set->id,$id,json_encode($fixture));$f=json_decode($f,true);
$r['database']=$f['database'];$r['media']=$f['media'];
$manifest=RecoveryManifest::assemble(RecoverySet::fromIntent($r['intent']),$r['environment'],$r['database'],$r['media'])->encode();
$r['phase']='complete';$r['manifest']=$manifest;
$r['checkpoint']=['bucket'=>$set->bucket,'key'=>RecoverySet::fromIntent($r['intent'])->root().'manifest.json','size_bytes'=>strlen($manifest),'sha256'=>hash('sha256',$manifest),'version_id'=>null,'etag'=>'fixture'];
// Use the receipt schema from the contract fixture, changing only set-bound bytes.
$receipt=$completeRecord['checkpoint'];
$receipt=json_decode(str_replace($set->id,$id,json_encode($receipt)),true);
$receipt['sha256']=hash('sha256',$manifest);$receipt['size_bytes']=strlen($manifest);$r['checkpoint']=$receipt;
$records[$id]->bytes=json_encode($r);$complete=$records[$id]->bytes;
$check((new RecoveryCollector($records[$id]))->current()['phase']==='complete','rollover fixture valid');
$check($make()->handle()['reason_code']==='next_slot_reserved','only completed set rolls over');
$check($make()->handle()['set_id']!==$id && count($records)===2 && $records[$id]->bytes===$complete,'new slot preserves complete history');
$gate->bytes='{}';$check($make()->handle()['phase']==='unknown','corrupt schedule not replaced');
// A lost initial CAS acknowledgement can be recovered without generating another ID.
$gate=new CronMemoryStore();$records=[];$gate->loseAck=true;
$make2=static fn()=>new RecoverySetCronHandler($gate,$stores,$factory,$config,$clock);
$check($make2()->handle()['phase']==='unknown' && count($records)===0,'lost acceptance ACK starts no adapter');
$check($make2()->handle()['phase']==='database' && count($records)===1,'retry reconciles reserved intent');
$gate=new CronMemoryStore();$gate->contend=true;
$handler=new RecoverySetCronHandler($gate,$stores,$factory,$config,$clock);$before=$step->calls;
$check($handler->handle()['phase']==='busy' && $step->calls===$before,'initial CAS loser performs no work');
$gate=new CronMemoryStore();$records=[];$factoryOffline=true;
$flaky=static function()use(&$factoryOffline,$factory):array {
    if($factoryOffline)throw new RuntimeException('SECRET adapter unavailable');return $factory();
};
$handler=new RecoverySetCronHandler($gate,$stores,$flaky,$config,$clock);
$check($handler->handle()['phase']==='unknown' && count($records)===1,'adapter failure preserves accepted set');
$acceptedId=array_key_first($records);$factoryOffline=false;
$check($handler->handle()['set_id']===$acceptedId && count($records)===1,'adapter recovery resumes accepted set');
// Collector insert succeeded but its acknowledgement was lost, before activation.
$gate=new CronMemoryStore();$records=[];$loseFirst=true;
$ackStores=static function(string $id)use($stores,&$loseFirst):JobStore {
    $store=$stores($id);if($loseFirst){$store->loseAck=true;$loseFirst=false;}return $store;
};
$handler=new RecoverySetCronHandler($gate,$ackStores,$factory,$config,$clock);
$check($handler->handle()['phase']==='unknown' && json_decode($gate->bytes,true)['state']==='initializing','collector ACK loss retains initializing');
$check($handler->handle()['phase']==='database' && count($records)===1,'initialization resumes after collector ACK loss');
echo 'PASS recovery Cron checks='.($checks-$initial)."\n";
