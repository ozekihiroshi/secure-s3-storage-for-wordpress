<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

use Closure;
use Throwable;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;

/** Ready means prepared. Only explicit publish() can advance to remotely verified complete. */
final class RecoveryCollector
{
    private Closure $clock;

    public function __construct(private JobStore $store, ?Closure $clock = null, private int $leaseSeconds = 60, private int $maxAttempts = 3)
    {
        Contract::require($leaseSeconds > 0 && $maxAttempts > 0);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function enqueue(RecoverySet $set, array $environment): bool
    {
        Contract::require($set->state === 'collecting');
        RecoveryManifest::validateEnvironment($environment);
        // No mutable settings are consulted after this immutable intent is saved.
        return $this->store->compareAndSwap(null, $this->encode([
            'schema' => 1, 'intent' => $set->toIntent(), 'environment' => $environment, 'phase' => 'database',
            'checkpoint' => [], 'database' => null, 'media' => null, 'manifest' => null,
            'lease' => '', 'until' => 0, 'attempts' => 0, 'error' => '',
        ]));
    }

    public function current(): ?array
    {
        $bytes = $this->store->read();
        return $bytes === null ? null : $this->decode($bytes);
    }

    public function tick(string $id, RecoveryStep $step): string
    {
        if ($step instanceof RecoveryLock && ! $step->acquire()) { return 'busy'; }
        try {
            return $this->runTick($id, $step);
        } finally {
            if ($step instanceof RecoveryLock) { $step->release(); }
        }
    }

    public function publish(string $id, RecoveryPublisher $publisher): string
    {
        if (! $publisher->acquire()) { return 'busy'; }
        try {
            $observed = $this->store->read();
            if ($observed === null) { return 'missing'; }
            $r = $this->decode($observed);
            if ($r['intent']['id'] !== $id) { return 'mismatch'; }
            if (! in_array($r['phase'], ['ready', 'publishing'], true)) { return $r['phase']; }
            $now = ($this->clock)();
            if ($r['until'] > $now) { return 'busy'; }
            if ($r['attempts'] >= $this->maxAttempts) {
                $r['phase'] = 'failed'; $r['error'] = 'recovery_exhausted'; $r['lease'] = ''; $r['until'] = 0;
                return $this->store->compareAndSwap($observed, $this->encode($r)) ? 'failed' : 'contended';
            }
            $r['phase'] = 'publishing';
            $r['lease'] = bin2hex(random_bytes(16)); $r['until'] = $now + $this->leaseSeconds; ++$r['attempts'];
            $claim = $this->encode($r);
            // Freeze publishing intent BEFORE any S3 request. Lost acknowledgements replay these bytes.
            if (! $this->store->compareAndSwap($observed, $claim)) { return 'contended'; }
            try {
                $set = RecoverySet::fromIntent($r['intent']);
                $receipt = $publisher->publish($set, $r['manifest'], $r['until']);
                RecoveryPublisher::validateReceipt($set, $r['manifest'], $receipt);
                $r['checkpoint'] = $receipt; $r['phase'] = 'complete'; $r['attempts'] = 0;
            } catch (RetryableJobException $e) {
                // Remote object may exist. Reconcile it before any subsequent conditional PUT.
            } catch (Throwable $e) {
                $r['phase'] = 'failed'; $r['error'] = 'publication_failed';
            }
            if (($this->clock)() >= $r['until']) { return 'lease_lost'; }
            $r['lease'] = ''; $r['until'] = 0;
            return $this->store->compareAndSwap($claim, $this->encode($r)) ? $r['phase'] : 'lease_lost';
        } finally { $publisher->release(); }
    }

    private function runTick(string $id, RecoveryStep $step): string
    {
        $observed = $this->store->read();
        if ($observed === null) { return 'missing'; }
        $r = $this->decode($observed);
        if ($r['intent']['id'] !== $id) { return 'mismatch'; }
        if (in_array($r['phase'], ['ready', 'publishing', 'complete', 'failed'], true)) { return $r['phase']; }
        $now = ($this->clock)();
        if ($r['until'] > $now) { return 'busy'; }
        if ($r['attempts'] >= $this->maxAttempts) {
            $r['phase'] = 'failed'; $r['error'] = 'recovery_exhausted'; $r['lease'] = ''; $r['until'] = 0;
            return $this->store->compareAndSwap($observed, $this->encode($r)) ? 'failed' : 'contended';
        }
        $r['lease'] = bin2hex(random_bytes(16)); $r['until'] = $now + $this->leaseSeconds; ++$r['attempts'];
        $claim = $this->encode($r);
        if (! $this->store->compareAndSwap($observed, $claim)) { return 'contended'; }
        $claimed = $r;
        try {
            $set = RecoverySet::fromIntent($r['intent']);
            if ($r['phase'] === 'assemble') {
                $manifest = RecoveryManifest::assemble($set, $r['environment'], $r['database'], $r['media']);
                $r['manifest'] = $set->publish($manifest)->manifestBytes;
                $r['phase'] = 'ready';
            } else {
                $progress = $step->execute($set, $r['phase'], $r['checkpoint'], $r['until']);
                if ($progress->evidence !== null) {
                    RecoveryManifest::validateEvidence($set, $r['phase'], $progress->evidence);
                    $r[$r['phase']] = $progress->evidence;
                    $r['phase'] = $r['phase'] === 'database' && $set->profile === 'database_uploads' ? 'media' : 'assemble';
                } else {
                    Contract::require($r['checkpoint'] !== $progress->checkpoint);
                }
                $r['checkpoint'] = $progress->checkpoint;
            }
            $r['attempts'] = 0;
        } catch (RecoveryPending $e) {
            $r = $claimed;
            $r['attempts'] = 0;
        } catch (RetryableJobException $e) {
            $r = $claimed; // Replay exactly the last durable checkpoint.
        } catch (Throwable $e) {
            $r = $claimed;
            $r['phase'] = 'failed'; $r['error'] = 'collection_failed';
        }
        if (($this->clock)() >= $claimed['until']) { return 'lease_lost'; }
        $r['lease'] = ''; $r['until'] = 0;
        if (! $this->store->compareAndSwap($claim, $this->encode($r))) { return 'lease_lost'; }
        return $r['phase'];
    }

    private function encode(array $r): string
    {
        $bytes = json_encode($r, JSON_THROW_ON_ERROR, 24);
        Contract::require(strlen($bytes) <= 196608);
        return $bytes;
    }

    private function decode(string $bytes): array
    {
        Contract::require(strlen($bytes) <= 196608);
        $r = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
        Contract::fields($r, ['schema', 'intent', 'environment', 'phase', 'checkpoint', 'database', 'media', 'manifest', 'lease', 'until', 'attempts', 'error']);
        Contract::require($r['schema'] === 1 && in_array($r['phase'], ['database', 'media', 'assemble', 'ready', 'publishing', 'complete', 'failed'], true));
        $set = RecoverySet::fromIntent($r['intent']);
        Contract::require(is_array($r['checkpoint']) && is_array($r['environment']));
        Contract::require(strlen(json_encode($r['checkpoint'], JSON_THROW_ON_ERROR, 16)) <= 32768);
        RecoveryManifest::validateEnvironment($r['environment']);
        Contract::count($r['until']); Contract::count($r['attempts']);
        Contract::require(is_string($r['lease']) && (($r['lease'] === '') === ($r['until'] === 0)));
        if ($r['lease'] !== '') { Contract::id($r['lease']); }
        Contract::require(in_array($r['error'], ['', 'collection_failed', 'publication_failed', 'recovery_exhausted'], true)
            && (($r['phase'] === 'failed') === ($r['error'] !== '')));
        foreach (['database', 'media'] as $kind) {
            if ($r[$kind] !== null) { RecoveryManifest::validateEvidence($set, $kind, $r[$kind]); }
        }
        if ($r['phase'] === 'database') { Contract::require($r['database'] === null && $r['media'] === null); }
        if ($r['phase'] === 'media') { Contract::require($r['media'] === null); }
        if ($r['phase'] === 'failed') { Contract::require($r['lease'] === ''); }
        if ($r['phase'] === 'media') { Contract::require($set->profile === 'database_uploads' && $r['database'] !== null); }
        if (in_array($r['phase'], ['assemble', 'ready', 'publishing', 'complete'], true) || $r['manifest'] !== null) {
            Contract::require($r['database'] !== null && ($set->profile === 'database' || $r['media'] !== null));
        }
        if (in_array($r['phase'], ['ready', 'publishing', 'complete'], true) || $r['manifest'] !== null) {
            Contract::require(in_array($r['phase'], ['ready', 'publishing', 'complete', 'failed'], true));
            if ($r['phase'] === 'complete') {
                Contract::require($r['lease'] === '');
                RecoveryPublisher::validateReceipt($set, $r['manifest'], $r['checkpoint']);
            } else { Contract::require($r['checkpoint'] === []); }
            if ($r['phase'] === 'ready') { Contract::require($r['lease'] === ''); }
            $expected = RecoveryManifest::assemble($set, $r['environment'], $r['database'], $r['media'])->encode();
            Contract::require($r['manifest'] === $expected);
        } else { Contract::require($r['manifest'] === null); }
        return $r;
    }
}
