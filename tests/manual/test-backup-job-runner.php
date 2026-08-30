<?php

use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\JobStep;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\StepResult;

// Standalone: no WordPress, AWS, network or user files are modified.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\Backup\\Job\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/Backup/Job/' . substr($class, strlen($prefix)) . '.php';
    }
});

final class TestJobStore implements JobStore
{
    public ?string $record = null;
    public bool $reject = false;
    public bool $broken = false;
    public function read(): ?string { return $this->record; }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($this->broken) { throw new RuntimeException('Storage failure.'); }
        if ($this->reject || $expected !== $this->record) { return false; }
        $this->record = $replacement;
        return true;
    }
}

final class TestJobStep implements JobStep
{
    public int $calls = 0;
    public function __construct(private Closure $callback) {}
    public function execute(BackupJob $job, int $deadline): StepResult
    {
        ++$this->calls;
        return ($this->callback)($job, $deadline);
    }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$throws = static function (Closure $callback, string $message) use ($check): void {
    try { $callback(); } catch (Throwable $e) { $check(true, $message); return; }
    $check(false, $message);
};
$time = 1000;
$clock = static function () use (&$time): int { return $time; };
$finish = new TestJobStep(static fn () => new StepResult([], 0, 0, true));

try {
    $store = new TestJobStore();
    $runner = new JobRunner($store, $clock, 10);
    $check($runner->tick(str_repeat('a', 32), 'media', $finish) === 'missing', 'Missing job.');
    $job = $runner->enqueue('media');
    $check($job instanceof BackupJob, 'Queue job.');
    $check($runner->enqueue('database') === null, 'Duplicate submission must fail.');
    $check($runner->tick($job->id, 'database', $finish) === 'mismatch', 'Wrong handler rejected.');
    $check($runner->tick(str_repeat('b', 32), 'media', $finish) === 'mismatch', 'Old event rejected.');
    $check($finish->calls === 0, 'Mismatched jobs never execute.');

    $step = new TestJobStep(static function (BackupJob $claimed) use ($runner, $finish, $check): StepResult {
        $check($runner->tick($claimed->id, 'media', $finish) === 'busy', 'Concurrent worker excluded.');
        return new StepResult(['after' => '2026/photo.jpg'], 1, 123);
    });
    $check($runner->tick($job->id, 'media', $step) === 'running', 'Checkpoint step.');
    $state = BackupJob::decode($store->record);
    $check($state->leaseToken === '' && $state->attempts === 0, 'Release lease/reset recovery count.');
    $check($state->processedFiles === 1 && $state->processedBytes === 123, 'Progress persisted.');
    $check(BackupJob::decode($state->encode())->encode() === $state->encode(), 'Round trip.');

    $runner = new JobRunner($store, $clock, 10);
    $resume = new TestJobStep(static function (BackupJob $claimed) use ($check): StepResult {
        $check($claimed->checkpoint['after'] === '2026/photo.jpg', 'Cursor survives worker restart.');
        return new StepResult(['manifest' => 'complete'], 2, 456, true);
    });
    $check($runner->tick($job->id, 'media', $resume) === 'succeeded', 'Complete backup.');
    $check($runner->tick($job->id, 'media', $finish) === 'succeeded', 'Terminal job not rerun.');
    $check($runner->enqueue('media') === null, 'Preserve terminal history until archived.');
    $check($finish->calls === 0, 'No duplicate execution.');

    // Hard crash recovery: a claimed record survives the process that claimed it.
    $store = new TestJobStore();
    $runner = new JobRunner($store, $clock, 10);
    $job = $runner->enqueue('database');
    $store->record = $job->claim($time, 10)->encode();
    $time += 10;
    $check($runner->tick($job->id, 'database', $finish) === 'succeeded', 'Expired lease recoverable.');

    $store = new TestJobStore();
    $runner = new JobRunner($store, $clock, 10);
    $job = $runner->enqueue('media');
    $slow = new TestJobStep(static function () use (&$time, $runner, $job, $finish, $check): StepResult {
        $time += 10;
        $check($runner->tick($job->id, 'media', $finish) === 'succeeded', 'Replacement worker completes.');
        return new StepResult([], 999, 999, true);
    });
    $check($runner->tick($job->id, 'media', $slow) === 'lease_lost', 'Stale worker rejected.');
    $check(BackupJob::decode($store->record)->processedFiles === 0, 'Winner state preserved.');

    $store = new TestJobStore();
    $runner = new JobRunner($store, $clock, 10, 3);
    $job = $runner->enqueue('media');
    $late = new TestJobStep(static function () use (&$time): StepResult {
        $time += 10;
        return new StepResult([], 1, 1, true);
    });
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        $check($runner->tick($job->id, 'media', $late) === 'lease_lost', 'Late completion rejected.');
    }
    $check($runner->tick($job->id, 'media', $late) === 'failed', 'Bounded recovery.');
    $check($late->calls === 3, 'No unbounded retry.');
    $check(BackupJob::decode($store->record)->errorCode === 'recovery_exhausted', 'Safe recovery error.');

    foreach ([
        static function (): StepResult { throw new RuntimeException('SECRET_SESSION_TOKEN'); },
        static fn () => new StepResult(['data' => str_repeat('x', 40000)], 1, 1),
        static fn () => new StepResult([], 0, 0),
    ] as $callback) {
        $store = new TestJobStore();
        $runner = new JobRunner($store, $clock, 10);
        $job = $runner->enqueue('media');
        $check($runner->tick($job->id, 'media', new TestJobStep($callback)) === 'failed', 'Invalid step fails.');
        $check(! str_contains($store->record, 'SECRET_SESSION_TOKEN'), 'Do not persist exception message.');
        $check(BackupJob::decode($store->record)->errorCode === 'step_failed', 'Safe step error.');
    }

    $store = new TestJobStore();
    $runner = new JobRunner($store, $clock);
    $job = $runner->enqueue('media');
    $store->reject = true;
    $never = new TestJobStep(static fn () => new StepResult([], 0, 0, true));
    $check($runner->tick($job->id, 'media', $never) === 'contended', 'CAS loser stops.');
    $check($never->calls === 0, 'CAS loser must not perform I/O.');
    $store->reject = false;
    $lostWrite = new TestJobStep(static function () use ($store): StepResult {
        $store->reject = true;
        return new StepResult([], 1, 1, true);
    });
    $check($runner->tick($job->id, 'media', $lostWrite) === 'lease_lost', 'CAS write failure cannot report success.');
    $check(BackupJob::decode($store->record)->status === 'running', 'Uncommitted success not persisted.');

    $store = new TestJobStore();
    $store->broken = true;
    $throws(static fn () => (new JobRunner($store))->enqueue('media'), 'Storage error fails closed.');
    foreach (['{}', 'null', '"x"', '{', '{"schema":2,"job":{}}'] as $bad) {
        $throws(static fn () => BackupJob::decode($bad), 'Corrupt state rejected.');
    }
    $state = new BackupJob(str_repeat('a', 32), 'media');
    $bad = json_decode($state->encode(), true);
    $bad['job']['processedFiles'] = '123';
    $throws(static fn () => BackupJob::decode(json_encode($bad)), 'No scalar type coercion.');
    $throws(static fn () => new JobRunner(new TestJobStore(), leaseSeconds: 0), 'Invalid lease.');
    $throws(static fn () => $state->advance(new StepResult([], 1, 1)), 'Unclaimed transition.');
    $withProgress = new BackupJob(str_repeat('b', 32), 'media', processedFiles: 2, processedBytes: 20);
    $throws(static fn () => $withProgress->claim(100, 10)->advance(new StepResult([], 1, 10)), 'No backwards progress.');

    echo "Backup job runner verification: OK ($checks checks)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup job runner verification failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
