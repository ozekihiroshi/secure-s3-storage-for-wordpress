<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Cron;

use Closure;
use RuntimeException;
use Throwable;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\{RecoveryRecordSnapshot,RecoveryStatus};

/** Read-time assessment of the current expected slot. No repair, writes or remote calls. */
final class RecoveryScheduleStatus
{
    public function __construct(private JobStore $policy,private JobStore $execution,private Closure $sets,private string $source) {}

    public function read(int $now):array
    {
        RecoverySchedulePolicy::epoch($now);
        $out=['schema_version'=>1,'source_id'=>$this->source,'evaluated_at'=>self::date($now),
            'state'=>'unknown','reason_code'=>null,'policy'=>null,'slot_at'=>null,'next_slot_at'=>null,
            'start'=>null,'completion'=>null,'scope'=>'current_slot_local_records_only'];
        try {
            $policyBytes=$this->policy->read();
            $p=(new RecoverySchedulePolicy(new RecoveryRecordSnapshot($policyBytes),$this->source))->read();
            if ($p===null) {
                if ($this->policy->read()!==$policyBytes) throw new RuntimeException('Policy changed during read.');
                $out['state']='not_configured'; return $out;
            }
            $out['policy']=$p;
            if ($p['updated_at']>$now) throw new RuntimeException('Future configuration.');
            $s=$p['settings'];
            if ($s['mode']==='stopped') {
                $out['state']='stopped';
            } else {
                $executionBytes=$this->execution->read();
                $e=(new RecoveryExecutionRecord(new RecoveryRecordSnapshot($executionBytes),$this->source))->read();
                foreach (['latest_run','last_result'] as $key) {
                    if ($e[$key]!==null) {
                        self::notFuture($e[$key]['started_at'],$now);
                        if ($e[$key]['finished_at']!==null) self::notFuture($e[$key]['finished_at'],$now);
                    }
                }
                foreach (['last_progress','last_success'] as $key) if ($e[$key]!==null) self::notFuture($e[$key]['at'],$now);
                if ($now<$s['anchor_at']) {
                    $out['state']='not_due'; $out['next_slot_at']=self::date($s['anchor_at']);
                } else {
                    $slot=$s['anchor_at']+intdiv($now-$s['anchor_at'],$s['interval_seconds'])*$s['interval_seconds'];
                    $out['slot_at']=self::date($slot);$out['next_slot_at']=self::date($slot+$s['interval_seconds']);
                    $start=$e['latest_run']['started_at']??null;
                    if ($start!==null && strtotime($start)<$slot) $start=null;
                    $out['start']=self::assessment($now,$slot+$s['start_grace_seconds'],$start,'observed');
                    $success=null;
                    if ($e['last_success']!==null) {
                        $id=$e['last_success']['set_id'];$store=($this->sets)($id);$bytes=$store->read();
                        $v=RecoveryStatus::read(new RecoveryRecordSnapshot($bytes),$id);
                        if ($v===null || $v['source_id']!==$this->source || $v['phase']!=='complete'
                            || $v['manifest']['publication_checked_at']!==$e['last_success']['at']) throw new RuntimeException('Success evidence unavailable.');
                        self::notFuture($v['created_at'],$now);
                        if (strtotime($v['created_at'])>=$slot) $success=$e['last_success']['at'];
                        if ($store->read()!==$bytes) throw new RuntimeException('Set changed during read.');
                    }
                    $out['completion']=self::assessment($now,$slot+$s['completion_grace_seconds'],$success,'complete');
                    $out['state']=in_array('overdue',[$out['start']['state'],$out['completion']['state']],true)
                        ? 'overdue' : ($success!==null?$out['completion']['state']:'within_grace');
                }
                if ($this->execution->read()!==$executionBytes) throw new RuntimeException('Execution changed during read.');
            }
            if ($this->policy->read()!==$policyBytes) throw new RuntimeException('Policy changed during read.');
            return $out;
        } catch (Throwable $e) {
            $out['state']='unknown';$out['reason_code']='schedule_evidence_unavailable';
            $out['start']=null;$out['completion']=null;
            return $out;
        }
    }

    private static function assessment(int $now,int $deadline,?string $observed,string $met):array
    {
        $delay=$observed!==null && $met==='complete'?max(0,strtotime($observed)-$deadline):null;
        return ['state'=>$observed!==null?($delay>0?'complete_late':$met):($now>$deadline?'overdue':'within_grace'),
            'deadline_at'=>self::date($deadline),'observed_at'=>$observed,
            'overdue_seconds'=>$observed===null?max(0,$now-$deadline):0,'completion_delay_seconds'=>$delay];
    }
    private static function notFuture(string $time,int $now):void { if (strtotime($time)>$now) throw new RuntimeException('Future evidence.'); }
    private static function date(int $time):string { RecoverySchedulePolicy::epoch($time);return gmdate('Y-m-d\TH:i:s\Z',$time); }
}
