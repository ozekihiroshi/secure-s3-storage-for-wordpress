<?php

use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\JobStep;
use SecureS3StorageForWordpress\Backup\Job\StepResult;
use SecureS3StorageForWordpress\Backup\Media\MediaFileHashStep;
use SecureS3StorageForWordpress\Backup\Media\MediaHashCheckpointStore;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;

spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

final class HashTestJobStore implements JobStore
{
    public function __construct(public ?string $value) {}
    public function read(): ?string { return $this->value; }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($this->value !== $expected) { return false; }
        $this->value = $replacement;
        return true;
    }
}

function makeHashTestStep(string $base, int $budget = 8388608, ?Closure $clock = null): MediaFileHashStep
{
    $source = new MediaSource($base . '/web/uploads');
    return new MediaFileHashStep($source, new MediaHashCheckpointStore($base . '/private', $source, $base . '/web'), $budget, $clock);
}

if (($argv[1] ?? '') === '--child') {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $store = new HashTestJobStore($input['job']);
    $job = BackupJob::decode($store->read());
    $step = makeHashTestStep($input['base']);
    if (($input['mode'] ?? '') === 'die-before-cas') {
        // A genuinely separate PHP process exits after saving the private file,
        // but before CAS selection. The parent retains the last committed state.
        $claimed = $job->claim(time(), 60);
        $step->execute($claimed, $claimed->leaseUntil);
        exit(17);
    }
    $status = (new JobRunner($store))->tick($job->id, 'media', $step);
    echo json_encode(['job' => $store->read(), 'status' => $status, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
    exit(0);
}

function childHashTest(string $base, string $job, string $mode = ''): array
{
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __FILE__, '--child'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('No child process.'); }
    $input = json_encode(compact('base', 'job', 'mode'), JSON_THROW_ON_ERROR);
    for ($offset = 0; $offset < strlen($input); $offset += $written) {
        $written = fwrite($pipes[0], substr($input, $offset));
        if ($written === false || $written === 0) { throw new RuntimeException('Child input failed.'); }
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($mode === 'die-before-cas' && $code === 17) { return []; }
    if ($code !== 0) { throw new RuntimeException('Child error: ' . $error); }
    return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
}

$checks = 0;
function hashCheck(bool $ok, string $message): void
{
    global $checks;
    if (! $ok) { throw new RuntimeException($message); }
    ++$checks;
}
function hashThrows(callable $test, string $message): void
{
    try { $test(); } catch (Throwable $e) { hashCheck(true, $message); return; }
    throw new RuntimeException($message);
}
function hashJob(string $path): BackupJob
{
    return new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: ['phase' => 'file_hash', 'path' => $path]);
}
function hashExecute(MediaFileHashStep $step, BackupJob $job): StepResult
{
    $claimed = $job->claim(time(), 60);
    return $step->execute($claimed, $claimed->leaseUntil);
}

// No site paths accepted: every writable path below is a new synthetic fixture.
$base = sys_get_temp_dir() . '/odbfs3-hash-step-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
$exit = 0;
try {
    foreach (['web', 'web/uploads', 'private', 'other-private'] as $part) { mkdir($base . '/' . $part, 0700); }
    $large = $base . '/web/uploads/large.bin';
    $output = fopen($large, 'xb');
    $block = str_repeat("\0\xffSynthetic media\n", 4096);
    for ($i = 0; $i < 300; ++$i) {
        if (fwrite($output, $block) !== strlen($block)) { throw new RuntimeException('Fixture write failed.'); }
    }
    fwrite($output, 'tail'); fclose($output);
    $expected = hash_file('sha256', $large);
    $job = hashJob('large.bin');
    $record = $job->encode();
    $first = childHashTest($base, $record);
    $advanced = BackupJob::decode($first['job']);
    hashCheck($first['pid'] !== getmypid(), 'Independent PHP process.');
    hashCheck($first['status'] === 'running', 'Not backup success.');
    hashCheck($advanced->checkpoint['hash_offset'] > 0 && $advanced->checkpoint['hash_offset'] <= 8388608, 'Bounded first read.');
    $savedReference = $advanced->checkpoint['hash_checkpoint'];
    $savedPath = $base . '/private/' . $savedReference['id'] . '.json';
    hashCheck((fileperms($savedPath) & 0777) === 0600, 'Private checkpoint mode.');
    hashCheck(! str_contains($first['job'], 'Synthetic media') && ! str_contains($first['job'], 'HashContext'), 'No source/hash context in job option.');

    // Crash creates an orphan but cannot change the selected cursor/file.
    $before = file_get_contents($savedPath);
    childHashTest($base, $first['job'], 'die-before-cas');
    hashCheck(file_get_contents($savedPath) === $before, 'Crash cannot overwrite selected state.');
    $replayA = childHashTest($base, $first['job']);
    $replayB = childHashTest($base, $first['job']);
    $a = BackupJob::decode($replayA['job']); $b = BackupJob::decode($replayB['job']);
    hashCheck($a->checkpoint['hash_offset'] === $b->checkpoint['hash_offset'], 'Replay same bounded cursor.');
    hashCheck($a->checkpoint['hash_checkpoint']['id'] !== $b->checkpoint['hash_checkpoint']['id'], 'Workers use distinct immutable files.');
    $record = $replayA['job'];
    for ($i = 0; $i < 50; ++$i) {
        $current = BackupJob::decode($record);
        if ($current->checkpoint['phase'] === 'file_hashed') { break; }
        $next = childHashTest($base, $record);
        hashCheck($next['status'] === 'running', 'Hash step never reports backup success.');
        $new = BackupJob::decode($next['job']);
        hashCheck($new->checkpoint['hash_offset'] - $current->checkpoint['hash_offset'] <= 8388608, 'Every process read is bounded.');
        $record = $next['job'];
    }
    $done = BackupJob::decode($record);
    hashCheck(($done->checkpoint['file_sha256'] ?? '') === $expected, 'Independent full-file SHA-256 matches.');
    hashCheck($done->checkpoint['file_size'] === filesize($large), 'Full file size matches.');
    hashCheck($done->processedFiles === 0 && $done->processedBytes === 0 && ! $done->terminal(), 'Preparation is not uploaded progress.');

    $source = new MediaSource($base . '/web/uploads');
    $store = new MediaHashCheckpointStore($base . '/private', $source, $base . '/web');
    $saved = $store->load($savedReference);
    // Bad digest, truncation, permission changes, symlink and hardlink substitution.
    file_put_contents($savedPath, substr($before, 0, -1));
    hashThrows(fn () => $store->load($savedReference), 'Truncated state must fail.');
    file_put_contents($savedPath, $before);
    chmod($savedPath, 0644);
    hashThrows(fn () => $store->load($savedReference), 'Public state must fail.');
    chmod($savedPath, 0600);
    link($savedPath, $base . '/extra-link');
    hashThrows(fn () => $store->load($savedReference), 'Hardlink state must fail.');
    unlink($base . '/extra-link');
    rename($savedPath, $savedPath . '.original');
    symlink($savedPath . '.original', $savedPath);
    hashThrows(fn () => $store->load($savedReference), 'Symlink state must fail.');
    unlink($savedPath); rename($savedPath . '.original', $savedPath);
    hashThrows(fn () => $store->load(['id' => '../outside', 'sha256' => str_repeat('0', 64)]), 'Traversal reference must fail.');

    // Trusted-envelope corruption fixtures: reject before native context restore.
    foreach (['runtime', 'binding', 'offset', 'hash'] as $field) {
        $bad = $saved;
        $bad[$field] = $field === 'offset' ? -1 : 'invalid';
        $checkpoint = $advanced->checkpoint;
        $checkpoint['hash_checkpoint'] = $store->save($bad);
        $badJob = new BackupJob($job->id, 'media', checkpoint: $checkpoint);
        hashThrows(fn () => hashExecute(makeHashTestStep($base), $badJob), 'Reject altered ' . $field);
    }
    $wrongRun = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $advanced->checkpoint);
    hashThrows(fn () => hashExecute(makeHashTestStep($base), $wrongRun), 'State cannot cross job IDs.');
    hashThrows(fn () => new MediaHashCheckpointStore($base . '/web/uploads', $source, $base . '/web'), 'State outside uploads.');
    hashThrows(fn () => new MediaHashCheckpointStore($base . '/web', $source, $base . '/web'), 'State outside document root.');
    chmod($base . '/other-private', 0755);
    hashThrows(fn () => new MediaHashCheckpointStore($base . '/other-private', $source, $base . '/web'), 'Reject shared work directory.');
    chmod($base . '/other-private', 0700);

    // Source mutation between steps, same-length replacement and links.
    rename($large, $large . '.original'); file_put_contents($large, 'changed');
    hashThrows(fn () => hashExecute(makeHashTestStep($base), $advanced), 'Changed source must fail.');
    unlink($large); link($large . '.original', $large);
    hashThrows(fn () => hashExecute(makeHashTestStep($base), $advanced), 'Hardlinked source must fail.');
    unlink($large); symlink($large . '.original', $large);
    hashThrows(fn () => hashExecute(makeHashTestStep($base), $advanced), 'Symlink source must fail.');
    unlink($large); rename($large . '.original', $large);

    foreach (['empty' => '', '日本語.bin' => "abc\0\xff"] as $name => $content) {
        file_put_contents($base . '/web/uploads/' . $name, $content);
        $result = hashExecute(makeHashTestStep($base), hashJob($name));
        hashCheck($result->checkpoint['file_sha256'] === hash('sha256', $content), 'Empty/Unicode/binary file.');
        hashCheck(! $result->complete, 'Small file does not complete backup.');
    }

    // A worker whose lease expires after writing cannot select its new checkpoint.
    $now = 100;
    $clock = static function () use (&$now): int { return $now; };
    $leaseStore = new HashTestJobStore(hashJob('large.bin')->encode());
    $real = makeHashTestStep($base, 1024, $clock);
    $slow = new class($real, $now) implements JobStep {
        private $now;
        public function __construct(private JobStep $inner, int &$now) { $this->now =& $now; }
        public function execute(BackupJob $job, int $deadline): StepResult {
            $result = $this->inner->execute($job, $deadline);
            $this->now = $deadline;
            return $result;
        }
    };
    $id = BackupJob::decode($leaseStore->read())->id;
    hashCheck((new JobRunner($leaseStore, $clock))->tick($id, 'media', $slow) === 'lease_lost', 'Expired worker fenced.');
    hashCheck(! isset(BackupJob::decode($leaseStore->read())->checkpoint['hash_checkpoint']), 'Expired worker cursor not selected.');
    hashCheck((new JobRunner($leaseStore, $clock))->tick($id, 'media', $real) === 'running', 'Replacement worker can resume.');
    hashCheck(BackupJob::decode($leaseStore->read())->checkpoint['hash_offset'] === 1024, 'Recovery reads original cursor once.');

    // Replacement worker wins while the old one is still returning from its step.
    $now = 200;
    $contended = new HashTestJobStore(hashJob('large.bin')->encode());
    $inner = makeHashTestStep($base, 1024, $clock);
    $late = new class($inner, $contended, $clock, $now) implements JobStep {
        private $now;
        public ?array $orphan = null;
        public function __construct(private JobStep $inner, private JobStore $store, private Closure $clock, int &$now) { $this->now =& $now; }
        public function execute(BackupJob $job, int $deadline): StepResult {
            $result = $this->inner->execute($job, $deadline);
            $this->orphan = $result->checkpoint['hash_checkpoint'];
            $this->now = $deadline;
            (new JobRunner($this->store, $this->clock))->tick($job->id, 'media', $this->inner);
            return $result;
        }
    };
    $id = BackupJob::decode($contended->read())->id;
    hashCheck((new JobRunner($contended, $clock))->tick($id, 'media', $late) === 'lease_lost', 'Late worker loses to new lease.');
    $winner = BackupJob::decode($contended->read());
    hashCheck($winner->checkpoint['hash_checkpoint'] !== $late->orphan, 'Late worker cannot select its orphan.');
    hashCheck($winner->checkpoint['hash_offset'] === 1024, 'Winner progress retained.');

    // Detect source change within a step, after at least one buffer was read.
    $calls = 0;
    $mutatingClock = static function () use (&$calls, $large): int {
        if (++$calls === 3) { file_put_contents($large, 'extra', FILE_APPEND); }
        return time();
    };
    hashThrows(fn () => hashExecute(makeHashTestStep($base, 8388608, $mutatingClock), hashJob('large.bin')), 'Mutation during step must fail.');

    // Invalid deadline must not read any data or create a selected checkpoint.
    $tight = hashJob('large.bin')->claim(100, 1);
    $tightStep = makeHashTestStep($base, 1024, static fn (): int => 100);
    hashThrows(fn () => $tightStep->execute($tight, 101), 'Insufficient lease must fail safely.');

    // Missing and digest-corrupted files cannot reach native deserialization.
    hashThrows(fn () => $store->load(['id' => str_repeat('f', 32), 'sha256' => str_repeat('0', 64)]), 'Missing state must fail.');
    hashThrows(fn () => $store->load(['id' => $savedReference['id'], 'sha256' => str_repeat('0', 64)]), 'Incorrect integrity pointer must fail.');
    chmod($base . '/private', 0755);
    hashThrows(fn () => $store->save(['test' => true]), 'Directory permission change must fail.');
    chmod($base . '/private', 0700);
    rename($base . '/private', $base . '/private-original');
    mkdir($base . '/private', 0700);
    hashThrows(fn () => $store->save(['test' => true]), 'Directory replacement must fail.');
    rmdir($base . '/private'); rename($base . '/private-original', $base . '/private');
    symlink($base . '/private', $base . '/private-link');
    hashThrows(fn () => new MediaHashCheckpointStore($base . '/private-link', $source, $base . '/web'), 'Symlink work directory must fail.');
    unlink($base . '/private-link');

    echo 'Media file hash step: OK (' . $checks . ' checks; peak ' . memory_get_peak_usage(true) . " bytes)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $exit = 1;
} finally {
    // Remove only this test's newly generated tree, without following links.
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || ! is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $remove($path . '/' . $name); } }
        rmdir($path);
    };
    $remove($base);
}
exit($exit);
