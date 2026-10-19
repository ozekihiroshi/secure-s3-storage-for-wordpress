<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Cron;

use Closure;
use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\JobStore;

/** Bounded source-local summary, not a process heartbeat or an execution lock. */
final class RecoveryExecutionRecord
{
    public function __construct(private JobStore $store, private string $source) { self::id($source); }

    public function read(): array
    {
        $bytes = $this->store->read();
        if ($bytes === null) {
            return ['schema_version'=>1, 'source_id'=>$this->source, 'latest_run'=>null,
                'last_result'=>null, 'last_progress'=>null, 'last_success'=>null];
        }
        if (strlen($bytes)>8192) throw new RuntimeException('Invalid execution record.');
        $r=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
        self::fields($r,['schema_version','source_id','latest_run','last_result','last_progress','last_success']);
        if ($r['schema_version']!==1 || $r['source_id']!==$this->source) throw new RuntimeException('Invalid execution identity.');
        foreach (['latest_run','last_result'] as $key) {
            $run=$r[$key]; if ($run===null) continue;
            self::fields($run,['run_id','started_at','finished_at','result']); self::id($run['run_id']); self::time($run['started_at']);
            if ($run['finished_at']===null) {
                if ($key==='last_result' || $run['result']!==null) throw new RuntimeException('Invalid unfinished run.');
            } else {
                self::time($run['finished_at']);
                if ($run['finished_at']<$run['started_at']) throw new RuntimeException('Invalid execution time.');
                self::result($run['result']);
            }
        }
        foreach (['last_progress','last_success'] as $key) {
            $v=$r[$key]; if ($v===null) continue;
            self::fields($v,['set_id','at']); self::id($v['set_id']); self::time($v['at']);
        }
        return $r;
    }

    public function start(int $now): array
    {
        $run=['run_id'=>bin2hex(random_bytes(16)), 'started_at'=>gmdate('Y-m-d\TH:i:s\Z',$now), 'finished_at'=>null, 'result'=>null];
        $this->update(static function(array $r) use($run):array {
            if ($r['latest_run']!==null && $run['started_at']<$r['latest_run']['started_at']) throw new RuntimeException('Clock moved backwards.');
            $r['latest_run']=$run; return $r;
        });
        return $run;
    }

    public function finish(array $run, array $result, int $now, ?string $progressSet, ?array $success): void
    {
        self::result($result);
        $run['finished_at']=gmdate('Y-m-d\TH:i:s\Z',$now); $run['result']=$result;
        if ($run['finished_at']<$run['started_at']) throw new RuntimeException('Clock moved backwards.');
        $this->update(static function(array $r) use($run,$progressSet,$success):array {
            // A late return must not replace a more recently started invocation.
            if (($r['latest_run']['run_id']??null)===$run['run_id']) $r['latest_run']=$run;
            if ($r['last_result']===null || $r['last_result']['finished_at']<=$run['finished_at']) $r['last_result']=$run;
            if ($progressSet!==null && ($r['last_progress']===null || $r['last_progress']['at']<=$run['finished_at']))
                $r['last_progress']=['set_id'=>$progressSet,'at'=>$run['finished_at']];
            if ($success!==null && ($r['last_success']===null || $r['last_success']['at']<$success['at'])) $r['last_success']=$success;
            return $r;
        });
    }

    private function update(Closure $change): void
    {
        for ($attempt=0;$attempt<5;++$attempt) {
            $before=$this->store->read();
            $snapshot=new self(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryRecordSnapshot($before),$this->source);
            $next=json_encode($change($snapshot->read()),JSON_THROW_ON_ERROR);
            // Validate the complete replacement before any write.
            (new self(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryRecordSnapshot($next),$this->source))->read();
            if ($next===$before || $this->store->compareAndSwap($before,$next)) return;
        }
        throw new RuntimeException('Execution record contention.');
    }

    private static function result(mixed $r):void
    {
        self::fields($r,['schema_version','set_id','phase','reason_code']);
        if ($r['schema_version']!==1) throw new RuntimeException('Invalid execution result.');
        if ($r['set_id']!==null) self::id($r['set_id']);
        if (!in_array($r['phase'],['unknown','busy','blocked','failed','complete','accepted','database','media','assemble','ready','publishing','contended','lease_lost'],true)
            || !in_array($r['reason_code'],['state_or_adapter_unavailable','schedule_contended','configuration_changed','clock_moved_backwards','operator_review_required','already_complete','next_slot_reserved','collector_lease_active','publication_verified','collector_result'],true))
            throw new RuntimeException('Invalid execution result.');
    }
    private static function fields(mixed $v,array $keys):void { if (!is_array($v)||array_keys($v)!==$keys) throw new RuntimeException('Invalid execution schema.'); }
    private static function id(mixed $v):void { if (!is_string($v)||preg_match('/^[a-f0-9]{32}$/D',$v)!==1) throw new RuntimeException('Invalid execution identity.'); }
    private static function time(mixed $v):void { if (!is_string($v)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$v)!==1||gmdate('Y-m-d\TH:i:s\Z',strtotime($v))!==$v) throw new RuntimeException('Invalid execution time.'); }
}
