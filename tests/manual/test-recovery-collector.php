<?php

declare(strict_types=1);

use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryCollector;
use SecureS3StorageForWordpress\Backup\Recovery\RecoverySet;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryStep;
use SecureS3StorageForWordpress\Backup\Recovery\RecoveryProgress;

// Reuse the executable contract fixture and assertion helpers. No AWS/WordPress.
require __DIR__ . '/test-recovery-contract.php';
require_once __DIR__ . '/../../src/Backup/Job/JobStore.php';
require_once __DIR__ . '/../../src/Backup/Job/RetryableJobException.php';

/** Test-only durable CAS storage, limited to one newly allocated private file. */
final class RecoveryTestStore implements JobStore
{
    public bool $rejectWrite = false;
    public bool $loseAcknowledgement = false;
    public function __construct(private string $file) {}
    public function read(): ?string
    {
        $stream = fopen($this->file, 'rb');
        flock($stream, LOCK_SH);
        $value = stream_get_contents($stream);
        flock($stream, LOCK_UN); fclose($stream);
        return $value === '' ? null : $value;
    }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($this->rejectWrite) { return false; }
        $stream = fopen($this->file, 'r+b');
        flock($stream, LOCK_EX);
        try {
            $value = stream_get_contents($stream);
            if (($value === '' ? null : $value) !== $expected) { return false; }
            rewind($stream); ftruncate($stream, 0);
            if (fwrite($stream, $replacement) !== strlen($replacement) || ! fflush($stream)) {
                throw new RuntimeException('Fixture write failed.');
            }
            if ($this->loseAcknowledgement) { throw new RuntimeException('Simulated commit acknowledgement loss.'); }
            return true;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
}
final class RecoveryTestStep implements RecoveryStep
{
    public int $calls = 0;
    public function __construct(private Closure $action) {}
    public function execute(RecoverySet $set, string $phase, array $checkpoint, int $deadline): RecoveryProgress
    {
        ++$this->calls;
        return ($this->action)($set, $phase, $checkpoint, $deadline);
    }
}
$files = [];
$newStore = static function () use (&$files): RecoveryTestStore {
    $file = tempnam(sys_get_temp_dir(), 'odbfs3-recovery-test-');
    if ($file === false) { throw new RuntimeException('Fixture allocation failed.'); }
    chmod($file, 0600); $files[] = $file;
    return new RecoveryTestStore($file);
};
$initialChecks = $checks;
try {
    $store = $newStore();
    $clockValue = 1000;
    $clock = static function () use (&$clockValue): int { return $clockValue; };
    $runner = new RecoveryCollector($store, $clock);
    $check($runner->enqueue($set, $fixture['environment']), 'persist intent');
    $check(! $runner->enqueue($set, $fixture['environment']), 'duplicate enqueue does not replace intent');
    $step = new RecoveryTestStep(static function ($intent, $phase, $checkpoint) use ($fixture, $set): RecoveryProgress {
        if ($intent->toIntent() !== $set->toIntent()) { throw new RuntimeException('Changed destination'); }
        return $checkpoint === [] ? new RecoveryProgress(['offset' => 1]) : new RecoveryProgress([], $fixture[$phase]);
    });
    $check($runner->tick(str_repeat('f', 32), $step) === 'mismatch' && $step->calls === 0, 'old event has no side effects');
    foreach (['database', 'media', 'media', 'assemble', 'ready'] as $expected) {
        // Reconstruct store and collector from disk after every operation.
        $runner = new RecoveryCollector(new RecoveryTestStore($files[0]), $clock);
        $check($runner->tick($set->id, $step) === $expected, 'restart phase ' . $expected);
    }
    $ready = $runner->current();
    $check($ready['phase'] === 'ready' && $ready['manifest'] !== null && $ready['lease'] === '', 'prepared is not published');
    $before = $step->calls;
    $check($runner->tick($set->id, $step) === 'ready' && $step->calls === $before, 'ready is idempotent');
    $check(! $runner->enqueue($other, $fixture['environment']), 'terminal record cannot be overwritten');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock, 10);
    $runner->enqueue($set, $fixture['environment']);
    $late = new RecoveryTestStep(static function () use ($runner, $set, &$clockValue, $check): RecoveryProgress {
        $never = new RecoveryTestStep(static function (): RecoveryProgress { throw new RuntimeException('must not run'); });
        $check($runner->tick($set->id, $never) === 'busy' && $never->calls === 0, 'concurrent worker fenced');
        $clockValue += 10;
        return new RecoveryProgress(['lost' => true]);
    });
    $check($runner->tick($set->id, $late) === 'lease_lost', 'process loss at checkpoint boundary');
    $check($runner->current()['checkpoint'] === [], 'late checkpoint not persisted');
    $check((new RecoveryCollector($store, $clock, 10))->tick($set->id, $step) === 'database', 'lease expiry permits replay');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock, 10);
    $runner->enqueue($set, $fixture['environment']);
    $superseded = new RecoveryTestStep(static function () use ($store, $clock, &$clockValue, $set, $step): RecoveryProgress {
        $clockValue += 11;
        (new RecoveryCollector($store, $clock, 10))->tick($set->id, $step);
        return new RecoveryProgress(['stale' => true]);
    });
    $check($runner->tick($set->id, $superseded) === 'lease_lost', 'superseded worker rejected');
    $check($runner->current()['checkpoint'] === ['offset' => 1], 'new worker progress preserved');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']);
    $retry = new RecoveryTestStep(static function (): RecoveryProgress { throw new RetryableJobException('secret URL'); });
    for ($i = 0; $i < 3; ++$i) { $check($runner->tick($set->id, $retry) === 'database', 'bounded transient retry'); }
    $check($runner->tick($set->id, $retry) === 'failed' && $retry->calls === 3, 'retry exhaustion');
    $check(! str_contains($store->read(), 'secret'), 'raw exceptions not saved');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']);
    $wrong = new RecoveryTestStep(static function () use ($fixture): RecoveryProgress {
        $evidence = $fixture['database']; $evidence['set_id'] = str_repeat('f', 32);
        return new RecoveryProgress([], $evidence);
    });
    $check($runner->tick($set->id, $wrong) === 'failed' && $runner->current()['database'] === null, 'foreign evidence fails closed');
    $check($runner->tick($set->id, $step) === 'failed', 'failed never restarts');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']);
    $dbComplete = new RecoveryTestStep(static fn (): RecoveryProgress => new RecoveryProgress([], $fixture['database']));
    $check($runner->tick($set->id, $dbComplete) === 'media', 'DB evidence committed before media');
    $failure = new RecoveryTestStep(static function (): RecoveryProgress { throw new RuntimeException('private error'); });
    $check($runner->tick($set->id, $failure) === 'failed' && $runner->current()['database'] === $fixture['database'], 'media failure preserves DB evidence');
    $check($runner->current()['manifest'] === null, 'partial set has no manifest');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']);
    $lostReply = new RecoveryTestStep(static function () use ($store, $fixture): RecoveryProgress {
        $store->loseAcknowledgement = true;
        return new RecoveryProgress([], $fixture['database']);
    });
    $reject(static fn () => $runner->tick($set->id, $lostReply), 'lost checkpoint acknowledgement');
    $store->loseAcknowledgement = false;
    $check((new RecoveryCollector($store, $clock))->current()['phase'] === 'media', 're-read durable commit after lost response');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']);
    $noProgress = new RecoveryTestStep(static fn (): RecoveryProgress => new RecoveryProgress([]));
    $check($runner->tick($set->id, $noProgress) === 'failed', 'no-progress loop rejected');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $runner->enqueue($set, $fixture['environment']); $store->rejectWrite = true;
    $before = $step->calls;
    $check($runner->tick($set->id, $step) === 'contended' && $before === $step->calls, 'no I/O when claim rejected');

    $store = $newStore(); $runner = new RecoveryCollector($store, $clock);
    $dbSet = RecoverySet::intent($set->id, $set->sourceId, 'database', $set->bucket, $set->region, $set->prefix, $set->createdAt);
    $runner->enqueue($dbSet, $fixture['environment']);
    $dbStep = new RecoveryTestStep(static fn (): RecoveryProgress => new RecoveryProgress([], $fixture['database']));
    $check($runner->tick($set->id, $dbStep) === 'assemble', 'database-only skips media');
    $check($runner->tick($set->id, $dbStep) === 'ready' && $dbStep->calls === 1, 'assembly makes no upload calls');
    $reject(static fn () => (new RecoveryCollector($newStore()))->enqueue($set, []), 'reject environment before any work');
    echo 'PASS recovery collector: ' . ($checks - $initialChecks) . " checks\n";
} finally {
    foreach ($files as $file) { unlink($file); }
}
