<?php
declare(strict_types=1);
require __DIR__.'/test-recovery-cron.php';
use SecureS3StorageForWordpress\Cron\{RecoveryExecutionRecord,RecoverySetCronHandler};
use SecureS3StorageForWordpress\Cli\RecoverySetCommand;

$initial=$checks; $journal=new CronMemoryStore(); $source=$config['source_id'];
$execution=new RecoveryExecutionRecord($journal,$source);
$check($execution->read()['latest_run']===null,'old installation has no invented start');
$gate=new CronMemoryStore();$records=[];$step=new CronStep();
$factory=static fn()=>[$step,$publisher];
$handler=new RecoverySetCronHandler($gate,$stores,$factory,$config,$clock,$execution);
$r=$handler->handle();$view=$execution->read();
$check($r['execution_recording']==='recorded' && $view['latest_run']['finished_at']!==null,'start and finish persisted');
$check($view['last_progress']['set_id']===$r['set_id'],'committed checkpoint recorded');
$check($view['last_success']===null,'partial not success');
$progress=$view['last_progress'];$now+=2;$step->busy=true;$r=$handler->handle();
$check($execution->read()['last_progress']===$progress && $r['phase']==='busy','busy is not progress');$step->busy=false;
$changed=$config;$changed['bucket']='fixture-other-bucket';
$blocked=new RecoverySetCronHandler($gate,$stores,$factory,$changed,$clock,$execution);
$r=$blocked->handle();
$check($r['phase']==='blocked' && $execution->read()['last_result']['result']['reason_code']==='configuration_changed','blocked settings result persisted');
$step->fail=true;$now+=2;$r=$handler->handle();
$check($r['phase']==='failed' && $execution->read()['last_result']['result']['phase']==='failed','failure result persisted');
$check($execution->read()['last_progress']===$progress,'failure does not refresh progress');
$check(!str_contains($journal->bytes,'SECRET'),'no raw exception stored');
// Separate service instances model restart and overlapping returns.
$first=$execution->start(++$now);$second=(new RecoveryExecutionRecord($journal,$source))->start(++$now);
$result=['schema_version'=>1,'set_id'=>null,'phase'=>'busy','reason_code'=>'schedule_contended'];
$execution->finish($first,$result,++$now,null,null);
$check($execution->read()['latest_run']===$second,'late older completion cannot close newer run');
$check($execution->read()['last_result']['run_id']===$first['run_id'],'last completed invocation separate');
$check((new RecoveryExecutionRecord($journal,$source))->read()['latest_run']['finished_at']===null,'interrupted latest call remains unfinished after restart');
$execution->finish($second,$result,++$now,null,null);
$check($execution->read()['latest_run']['finished_at']!==null,'matching finish closes run');
$success=['set_id'=>str_repeat('a',32),'at'=>gmdate('Y-m-d\TH:i:s\Z',$now-1)];
$run=$execution->start(++$now);$execution->finish($run,$result,++$now,null,$success);
$run=$execution->start(++$now);$execution->finish($run,$result,++$now,null,null);
$check($execution->read()['last_success']===$success,'later non-success keeps last success');
$saved=$journal->bytes;$journal->bytes='SECRET broken';$before=$step->calls;
$check($handler->handle()['reason_code']==='execution_record_unavailable' && $step->calls===$before,'unreadable start record blocks work');
$journal->bytes=$saved;$journal->contend=true;
$check($handler->handle()['reason_code']==='execution_record_unavailable','bounded CAS failure');$journal->contend=false;
$before=$journal->bytes;$bad=false;try{$execution->start($now-100);}catch(Throwable $e){$bad=true;}
$check($bad && $journal->bytes===$before,'clock rollback does not rewrite record');
$journal->loseAck=true;$r=$handler->handle();
$check($r['reason_code']==='execution_record_unavailable' && $execution->read()['latest_run']['finished_at']===null,'lost start ACK leaves unknown without work');
// Actual handler end-write failure: preserve operation result and signal recording failure.
$gate=new CronMemoryStore();$records=[];$step->fail=false;
$handler=new RecoverySetCronHandler($gate,$stores,$factory,$config,$clock,$execution);
$step->during=static function()use($journal){$journal->contend=true;};
$r=$handler->handle();$journal->contend=false;$step->during=null;
$check($r['phase']==='database' && $r['execution_recording']==='unavailable','end record failure does not erase backup result');
$check($execution->read()['latest_run']['finished_at']===null,'end record failure is not falsely closed');
// Reuse a validated complete Collector fixture for this schedule's exact identity.
$now=strtotime($set->createdAt);$gate=new CronMemoryStore();$records=[];$journal=new CronMemoryStore();
$execution=new RecoveryExecutionRecord($journal,$source);
$handler=new RecoverySetCronHandler($gate,$stores,$factory,$config,$clock,$execution);
$handler->handle();
$active=json_decode($gate->bytes,true);$id=$active['intent']['id'];
$complete=json_decode(str_replace($set->id,$id,json_encode($completeRecord)),true);
$complete['intent']=$active['intent'];$complete['environment']=$active['configuration']['environment'];
$complete['checkpoint']['sha256']=hash('sha256',$complete['manifest']);
$complete['checkpoint']['size_bytes']=strlen($complete['manifest']);
$records[$id]->bytes=json_encode($complete);
(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryCollector($records[$id]))->current();
$now+=2;
$r=$handler->handle();$savedSuccess=$execution->read()['last_success'];
$check($r['phase']==='complete' && $savedSuccess['at']===$complete['checkpoint']['verified_at'],'handler records publication evidence time');
$now+=2;$oldProgress=$execution->read()['last_progress'];$handler->handle();
$check($execution->read()['last_success']===$savedSuccess && $execution->read()['last_progress']===$oldProgress,'already complete does not refresh progress or success');
// JSON is a read-only projection of the same record.
$db=new wpdb();$db->bytes=$journal->bytes;$GLOBALS['wpdb']=$db;$before=$db->bytes;WP_CLI::$lines=[];
(new RecoverySetCommand())->execution([],['source-id'=>$source,'format'=>'json']);
$out=json_decode(end(WP_CLI::$lines),true);
$check($out===$execution->read() && $db->bytes===$before,'JSON matches persisted record without writes');
$db->bytes='SECRET<script>';WP_CLI::$lines=[];$code=0;
try{(new RecoverySetCommand())->execution([],['source-id'=>$source,'format'=>'json']);}catch(StatusHalt $e){$code=$e->getCode();}
$check($code===4 && !str_contains(implode('',WP_CLI::$lines),'SECRET'),'invalid JSON record redacted');
echo 'PASS execution record checks='.($checks-$initial)."\n";
