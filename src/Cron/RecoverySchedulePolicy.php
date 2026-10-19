<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Cron;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Job\JobStore;

/** Explicit expectations only. Never schedules, cancels or starts a backup. */
final class RecoverySchedulePolicy
{
    public function __construct(private JobStore $store, private string $source)
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$source)!==1) throw new RuntimeException('Invalid source.');
    }

    public function read(): ?array
    {
        $bytes=$this->store->read();
        if ($bytes===null) return null;
        if (strlen($bytes)>2048) throw new RuntimeException('Invalid schedule policy.');
        $r=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($r) || array_keys($r)!==['schema_version','source_id','updated_at','settings']
            || $r['schema_version']!==1 || $r['source_id']!==$this->source) throw new RuntimeException('Invalid schedule policy.');
        self::epoch($r['updated_at']); self::settings($r['settings']);
        return $r;
    }

    /** Trusted configuration API with optimistic concurrency; not called by read views. */
    public function replace(?array $expected,array $settings,int $now): array
    {
        self::epoch($now); self::settings($settings);
        $bytes=$this->store->read();
        $current=(new self(new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryRecordSnapshot($bytes),$this->source))->read();
        if ($current!==$expected) throw new RuntimeException('Schedule policy changed.');
        if ($current!==null && $now<$current['updated_at']) throw new RuntimeException('Clock moved backwards.');
        if ($current!==null && $current['settings']===$settings) return $current;
        if (($current['settings']['mode']??null)==='stopped' && $settings['mode']==='enabled' && $settings['anchor_at']<$now)
            throw new RuntimeException('Resume requires a new current or future anchor.');
        $next=['schema_version'=>1,'source_id'=>$this->source,'updated_at'=>$now,'settings'=>$settings];
        if (!$this->store->compareAndSwap($bytes,json_encode($next,JSON_THROW_ON_ERROR))) throw new RuntimeException('Schedule policy changed.');
        return $next;
    }

    public static function settings(mixed $s): void
    {
        if (!is_array($s) || array_keys($s)!==['mode','anchor_at','interval_seconds','start_grace_seconds','completion_grace_seconds']
            || !in_array($s['mode'],['enabled','stopped'],true)) throw new RuntimeException('Invalid schedule settings.');
        self::epoch($s['anchor_at']);
        foreach (['interval_seconds','start_grace_seconds','completion_grace_seconds'] as $key)
            if (!is_int($s[$key])) throw new RuntimeException('Invalid schedule duration.');
        if ($s['interval_seconds']<60 || $s['interval_seconds']>31536000
            || $s['start_grace_seconds']<0 || $s['completion_grace_seconds']<$s['start_grace_seconds']
            || $s['completion_grace_seconds']>=$s['interval_seconds']) throw new RuntimeException('Invalid schedule duration.');
    }
    public static function epoch(mixed $v):void
    {
        if (!is_int($v) || $v<0 || $v>253402300799) throw new RuntimeException('Invalid schedule timestamp.');
    }
}
