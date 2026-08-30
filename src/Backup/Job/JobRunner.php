<?php

namespace SecureS3StorageForWordpress\Backup\Job;

use Closure;
use InvalidArgumentException;
use Throwable;

final class JobRunner
{
    private Closure $clock;

    public function __construct(
        private JobStore $store,
        ?Closure $clock = null,
        private int $leaseSeconds = 60,
        private int $maxRecoveryAttempts = 3,
    ) {
        if ($leaseSeconds < 1 || $maxRecoveryAttempts < 1) {
            throw new InvalidArgumentException('Invalid backup worker limits.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Submit into an empty slot only. History/cleanup must retire terminal jobs
     * explicitly; submitting must never erase an earlier result.
     */
    public function enqueue(string $type): ?BackupJob
    {
        $job = new BackupJob(bin2hex(random_bytes(16)), $type);

        return $this->store->compareAndSwap(null, $job->encode()) ? $job : null;
    }

    /**
     * One bounded step. Job type and ID bind a scheduled event to its handler;
     * old events must not accidentally operate on another job.
     */
    public function tick(string $id, string $type, JobStep $step): string
    {
        $observed = $this->store->read();
        if ($observed === null) {
            return 'missing';
        }
        $job = BackupJob::decode($observed);
        if ($job->id !== $id || $job->type !== $type) {
            return 'mismatch';
        }
        if ($job->terminal()) {
            return $job->status;
        }
        $now = ($this->clock)();
        if ($job->leaseUntil > $now) {
            return 'busy';
        }
        if ($job->attempts >= $this->maxRecoveryAttempts) {
            return $this->store->compareAndSwap($observed, $job->fail('recovery_exhausted')->encode())
                ? 'failed' : 'contended';
        }

        $claimed = $job->claim($now, $this->leaseSeconds);
        $claimedRecord = $claimed->encode();
        if (! $this->store->compareAndSwap($observed, $claimedRecord)) {
            return 'contended';
        }

        try {
            $result = $step->execute($claimed, $claimed->leaseUntil);
            $next = $claimed->advance($result);
            $nextRecord = $next->encode();
        } catch (Throwable $e) {
            // Never persist raw exceptions: SDK messages can contain signed requests.
            $next = $claimed->fail('step_failed');
            $nextRecord = $next->encode();
        }

        // Time expiry alone invalidates this worker, even before another claim.
        if (($this->clock)() >= $claimed->leaseUntil) {
            return 'lease_lost';
        }
        if (! $this->store->compareAndSwap($claimedRecord, $nextRecord)) {
            return 'lease_lost';
        }

        return $next->status;
    }
}
