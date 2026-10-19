<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Cron;

use Closure;
use RuntimeException;
use Throwable;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet, RecoveryManifest, RecoveryCollector, RecoveryStep, RecoveryLock, RecoveryPublisher};

/** Opt-in scheduler service. One Collector operation per invocation; no automatic WP schedule. */
final class RecoverySetCronHandler
{
    private Closure $clock;

    /** Factories receive the frozen set; adapters returns [locking step, publisher]. */
    public function __construct(
        private JobStore $schedule,
        private Closure $stores,
        private Closure $adapters,
        private array $configuration,
        ?Closure $clock = null,
        private ?RecoveryExecutionRecord $execution = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->validateConfiguration($configuration);
    }

    /** Safe to call from a trusted WP Cron callback or an operator's bootstrap. */
    public function handle(): array
    {
        $run = null;
        if ($this->execution !== null) {
            try { $run = $this->execution->start(($this->clock)()); }
            catch (Throwable $e) { return $this->result(null, 'unknown', 'execution_record_unavailable'); }
        }
        $before = $this->execution === null ? null : $this->progressSnapshot();
        try { $result = $this->advance(); }
        catch (Throwable $e) {
            // Unknown outcome is never a request to replace a set or remove its workspace.
            $result = $this->result(null, 'unknown', 'state_or_adapter_unavailable');
        }
        if ($run !== null) {
            try {
                $after=$this->progressSnapshot();
                $progress=$after!==null && $after!==$before && $result['reason_code']!=='already_complete'
                    && !in_array($result['phase'],['busy','blocked','unknown','failed','contended','lease_lost'],true)
                    ? $after['set_id'] : null;
                $success=$after['success']??$before['success']??null;
                $this->execution->finish($run,$result,($this->clock)(),$progress,$success);
                $result['execution_recording']='recorded';
            } catch (Throwable $e) {
                // Preserve the actual backup result; recording failure does not undo S3 work.
                $result['execution_recording']='unavailable';
            }
        }
        return $result;
    }

    /** Committed checkpoint evidence only; lease acquisition is not progress. */
    private function progressSnapshot(): ?array
    {
        try {
            $bytes=$this->schedule->read(); if ($bytes===null) return null;
            $r=$this->decode($bytes); $id=$r['intent']['id'];
            $c=(new RecoveryCollector(($this->stores)($id),$this->clock))->current();
            $this->assertBinding($c,$r);
            return ['set_id'=>$id, 'phase'=>$c['phase'], 'checkpoint'=>$c['checkpoint'],
                'database'=>$c['database'], 'media'=>$c['media'], 'manifest'=>$c['manifest'],
                'success'=>$c['phase']==='complete' ? ['set_id'=>$id,'at'=>$c['checkpoint']['verified_at']] : null];
        } catch (Throwable $e) { return null; }
    }

    private function advance(): array
    {
        $now = ($this->clock)();
        $day = gmdate('Y-m-d', $now);
        $bytes = $this->schedule->read();
        if ($bytes === null) {
            $next = $this->newRecord($day, $now);
            if (!$this->schedule->compareAndSwap(null, $this->encode($next))) {
                return $this->result(null, 'busy', 'schedule_contended');
            }
            $bytes = $this->encode($next);
        }
        $r = $this->decode($bytes);
        $set = RecoverySet::fromIntent($r['intent']);
        if ($r['configuration'] !== $this->configuration) {
            return $this->result($set->id, 'blocked', 'configuration_changed');
        }
        if ($day < $r['day']) { return $this->result($set->id, 'blocked', 'clock_moved_backwards'); }
        $collector = new RecoveryCollector(($this->stores)($set->id), $this->clock);
        $current = $collector->current();
        if ($r['state'] === 'initializing') {
            if ($current === null) {
                $collector->enqueue($set, $r['configuration']['environment']);
                $current = $collector->current();
            }
            $this->assertBinding($current, $r);
            $r['state'] = 'active';
            if (!$this->schedule->compareAndSwap($bytes, $this->encode($r))) {
                return $this->result($set->id, 'busy', 'schedule_contended');
            }
            $bytes = $this->encode($r);
        }
        // An active missing/corrupt Collector is evidence loss, never permission to recreate.
        $this->assertBinding($current, $r);
        if ($current['phase'] === 'failed') {
            return $this->result($set->id, 'failed', 'operator_review_required');
        }
        if ($current['phase'] === 'complete') {
            if ($day === $r['day']) { return $this->result($set->id, 'complete', 'already_complete'); }
            // Skip missed days, do not pretend to have captured a historical snapshot.
            $next = $this->newRecord($day, $now);
            if (!$this->schedule->compareAndSwap($bytes, $this->encode($next))) {
                return $this->result($set->id, 'busy', 'schedule_contended');
            }
            return $this->result($next['intent']['id'], 'accepted', 'next_slot_reserved');
        }
        if ($current['until'] > $now) { return $this->result($set->id, 'busy', 'collector_lease_active'); }
        [$step, $publisher] = ($this->adapters)($set, $r['configuration']['backend']);
        if (!$step instanceof RecoveryStep || !$step instanceof RecoveryLock || !$publisher instanceof RecoveryPublisher) {
            throw new RuntimeException('Locking recovery adapters required.');
        }
        $phase = in_array($current['phase'], ['ready', 'publishing'], true)
            ? $collector->publish($set->id, $publisher) : $collector->tick($set->id, $step);
        return $this->result($set->id, $phase, $phase === 'complete' ? 'publication_verified' : 'collector_result');
    }

    private function newRecord(string $day, int $now): array
    {
        $c = $this->configuration;
        // Source and slot, not destination: a settings edit must not mint another daily set.
        $id = substr(hash('sha256', 'odbfs3-daily-v1:' . $c['source_id'] . ':' . $day), 0, 32);
        $set = RecoverySet::intent($id, $c['source_id'], $c['profile'], $c['bucket'], $c['region'], $c['prefix'], gmdate('Y-m-d\TH:i:s\Z', $now));
        return ['schema'=>1, 'day'=>$day, 'state'=>'initializing', 'configuration'=>$c, 'intent'=>$set->toIntent()];
    }

    private function validateConfiguration(array $c): void
    {
        if (array_keys($c) !== ['source_id','profile','bucket','region','prefix','backend','environment']
            || !in_array($c['backend'], ['native','php'], true)) { throw new RuntimeException('Invalid schedule configuration.'); }
        RecoverySet::intent(str_repeat('0',32), $c['source_id'], $c['profile'], $c['bucket'], $c['region'], $c['prefix'], '2026-01-01T00:00:00Z');
        RecoveryManifest::validateEnvironment($c['environment']);
    }

    private function assertBinding(?array $current, array $r): void
    {
        if ($current === null || $current['intent'] !== $r['intent'] || $current['environment'] !== $r['configuration']['environment']) {
            throw new RuntimeException('Schedule binding unavailable.');
        }
    }

    private function encode(array $r): string { return json_encode($r, JSON_THROW_ON_ERROR, 16); }

    private function decode(string $bytes): array
    {
        if (strlen($bytes)>16384) { throw new RuntimeException('Invalid schedule.'); }
        $r = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($r) || array_keys($r)!==['schema','day','state','configuration','intent'] || $r['schema']!==1
            || !in_array($r['state'],['initializing','active'],true)) { throw new RuntimeException('Invalid schedule.'); }
        $this->validateConfiguration($r['configuration']);
        $s = RecoverySet::fromIntent($r['intent']);
        $c = $r['configuration'];
        if ($r['day']!==substr($s->createdAt,0,10) || $s->id!==substr(hash('sha256','odbfs3-daily-v1:'.$c['source_id'].':'.$r['day']),0,32)
            || [$s->sourceId,$s->profile,$s->bucket,$s->region,$s->prefix]!==[$c['source_id'],$c['profile'],$c['bucket'],$c['region'],$c['prefix']]) {
            throw new RuntimeException('Invalid schedule binding.');
        }
        return $r;
    }

    private function result(?string $id, string $phase, string $reason): array
    {
        return ['schema_version'=>1, 'set_id'=>$id, 'phase'=>$phase, 'reason_code'=>$reason];
    }
}
