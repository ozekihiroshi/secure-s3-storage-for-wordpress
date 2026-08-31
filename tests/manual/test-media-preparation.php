<?php

use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaPreparationStep;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\WordPress\MediaJobController;

define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
require __DIR__ . '/test-media-upload.php';

/** Test-only durable store so actual child exits leave a persisted lease/cursor. */
final class PreparationDiskStore implements JobStore
{
    public int $swaps = 0;
    public function __construct(private string $path, private bool $die = false) {}
    public function read(): ?string { return file_get_contents($this->path); }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($this->die && ++$this->swaps === 2) { exit(17); }
        $stream = fopen($this->path, 'r+b'); flock($stream, LOCK_EX);
        try {
            if (stream_get_contents($stream) !== $expected) { return false; }
            rewind($stream); ftruncate($stream, 0);
            if (fwrite($stream, $replacement) !== strlen($replacement)) { throw new RuntimeException('Test store write failed'); }
            fflush($stream); fsync($stream); return true;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
}

if (($argv[1] ?? '') === '--child') {
    $data = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $store = new PreparationDiskStore($data['job_file'], $data['die']);
    $job = BackupJob::decode($store->read());
    $step = new MediaPreparationStep(new MediaSource($job->checkpoint['root']), $job->checkpoint['directory'], $data['web'], 2);
    echo $step->tick($store, $job->id);
    exit(0);
}

function preparationChild(string $jobFile, string $web, bool $die = false): string
{
    $p = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __FILE__, '--child'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $input = json_encode(['job_file' => $jobFile, 'web' => $web, 'die' => $die], JSON_THROW_ON_ERROR);
    if (fwrite($pipes[0], $input) !== strlen($input)) { throw new RuntimeException('Test child input failed'); }
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    if ($die && $code === 17) { return 'crashed'; }
    if ($code !== 0) { throw new RuntimeException($err); }
    return $out;
}

$base = sys_get_temp_dir() . '/odbfs3-preparation-test-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
$checks = 0; $exit = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($message); } ++$checks;
};
$expire = static function (PreparationDiskStore $store): void {
    $j = BackupJob::decode($store->read());
    $expired = new BackupJob($j->id, $j->type, 'running', $j->checkpoint, $j->processedFiles, $j->processedBytes,
        $j->leaseToken, time() - 1, $j->attempts);
    if (! $store->compareAndSwap($j->encode(), $expired->encode())) { throw new RuntimeException('Test expiry failed'); }
};
try {
    foreach (['web', 'web/uploads', 'web/uploads/nested', 'private', 'remote', 'restored', 'empty'] as $dir) { mkdir($base . '/' . $dir, 0700); }
    define('ABSPATH', $base . '/web/');
    $root = $base . '/web/uploads';
    for ($i = 22; $i >= 0; --$i) { file_put_contents($root . '/file-' . $i . '.txt', 'fixture ' . $i); }
    file_put_contents($root . '/nested/日本語.png', "binary\0\xff");
    file_put_contents($root . '/empty', '');
    file_put_contents($root . '/.hidden', 'hidden');
    $out = fopen($root . '/large.bin', 'xb');
    for ($i = 0; $i < 17; ++$i) { fwrite($out, str_repeat(chr($i), 1048576)); }
    fclose($out);
    $source = new MediaSource($root);
    $s = MediaPreparationStep::initialize($source, $base . '/private', ABSPATH)
        + ['region' => 'ap-northeast-1', 'bucket' => 'test-bucket', 'prefix' => 'prepared/'];
    $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $s);
    $jobFile = $base . '/job.json'; file_put_contents($jobFile, $job->encode()); chmod($jobFile, 0600);
    $store = new PreparationDiskStore($jobFile);
    $check(! file_exists($s['directory'] . '/paths.jsonl') && ! file_exists($s['directory'] . '/ready.json'), 'Submission does not enumerate or publish ready.');

    $lock = fopen($s['directory'] . '/worker.lock', 'rb'); flock($lock, LOCK_EX);
    $check(preparationChild($jobFile, ABSPATH) === 'busy', 'Second process cannot mutate locked workspace.');
    $check($store->read() === $job->encode(), 'Busy worker does not claim job.');
    flock($lock, LOCK_UN); fclose($lock);
    $check(preparationChild($jobFile, ABSPATH, true) === 'crashed', 'Real process exits after directory writes before CAS.');
    $check(BackupJob::decode($store->read())->checkpoint['queue_cursor'] === 0, 'Crash does not select partial enumeration.');
    $expire($store);
    $check(preparationChild($jobFile, ABSPATH) === 'running', 'Enumeration recovers after process death.');

    $phases = []; $crashedMerge = $crashedHash = false;
    for ($i = 0; $i < 3000; ++$i) {
        $current = BackupJob::decode($store->read());
        if (! isset($current->checkpoint['phase'])) { break; }
        $phase = $current->checkpoint['phase']; $phases[$phase] = true;
        $check(! file_exists($s['directory'] . '/ready.json'), 'Readiness never precedes complete preparation.');
        if ((! $crashedMerge && $phase === 'sort_merge') || (! $crashedHash && $phase === 'parts')) {
            $check(preparationChild($jobFile, ABSPATH, true) === 'crashed', 'Process loss in ' . $phase);
            $expire($store);
            if ($phase === 'sort_merge') { $crashedMerge = true; } else { $crashedHash = true; }
        }
        // Fresh handler each time; periodically use an entirely new PHP process.
        $status = $i % 13 === 0 ? preparationChild($jobFile, ABSPATH)
            : (new MediaPreparationStep(new MediaSource($root), $s['directory'], ABSPATH, 2))->tick($store, $job->id);
        $check($status === 'running', 'Preparation step must advance, not fail/complete: ' . $phase . ' => ' . $status);
    }
    $prepared = BackupJob::decode($store->read());
    $check(! isset($prepared->checkpoint['phase']) && ! $prepared->terminal(), 'Prepared plan switches to upload, not success.');
    $check(isset($phases['sort_merge'], $phases['file_hash'], $phases['parts'], $phases['validate_directories']), 'All preparation phases exercised.');
    $beforeHandoff = $store->read();
    $check((new MediaPreparationStep(new MediaSource($root), $s['directory'], ABSPATH))->tick($store, $job->id) === 'running',
        'Stale preparation handler recognizes completed handoff.');
    $check($store->read() === $beforeHandoff, 'Stale preparation handler cannot fail or mutate upload job.');
    $plan = new MediaUploadPlan($s['directory']);
    $check($plan->metadata()['files'] === 27, 'Every nested/hidden/empty media file included once.');
    $check((new MediaManifest())->verify(new MediaSource($root), $s['directory'] . '/inventory.jsonl', $base)->successful(), 'Sorted inventory matches independent source walk and hashes.');
    $s3 = new UploadTestS3($base . '/remote'); $s3->pageSize = 1;
    $GLOBALS['test_upload_root'] = $root; $GLOBALS['test_events'] = [];
    $GLOBALS['test_options'] = ['secure_s3_storage_settings' => ['region' => 'ap-northeast-1', 'bucket' => 'test-bucket', 'prefix' => 'prepared/']];
    $controller = new MediaJobController($store, static fn ($region) => $s3);
    $controller->register();
    for ($i = 0; $i < 100 && ! $controller->current()->terminal(); ++$i) { $controller->run($job->id); }
    $check($controller->current()->status === 'succeeded', 'Prepared v1 plan works with existing uploader.');
    $prefix = 'test-bucket/prepared/backups/media/' . $job->id . '/';
    foreach ((new MediaManifest())->entries($s['directory'] . '/inventory.jsonl') as $entry) {
        $destination = $base . '/restored/' . $entry->path;
        if (! is_dir(dirname($destination))) { mkdir(dirname($destination), 0700, true); }
        copy($s3->objects[$prefix . 'files/' . hash('sha256', $entry->path)]['path'], $destination);
    }
    $check((new MediaManifest())->verify(new MediaSource($base . '/restored'), $s['directory'] . '/inventory.jsonl', $base)->successful(), 'S3 model restore: all paths, sizes and full SHA-256 match.');

    // Controller/Cron path from an UNPREPARED source, including schedule recovery.
    $memory = new UploadTestStore(); $remote = new UploadTestS3($base . '/remote'); $created = 0;
    $c = new MediaJobController($memory, static function ($region) use ($remote, &$created) { ++$created; return $remote; });
    $c->register(); $queued = $c->enqueuePreparation($base . '/private');
    $check($queued->status === 'queued' && $created === 0, 'No AWS client in submission.');
    try { $c->enqueuePreparation($base . '/private'); throw new LogicException('Duplicate accepted'); }
    catch (RuntimeException $e) { $check(true, 'Duplicate rejected'); }
    $GLOBALS['test_options']['secure_s3_storage_settings']['bucket'] = 'changed-bucket';
    wp_unschedule_hook(MediaJobController::HOOK); $c->recoverSchedule();
    $check(wp_next_scheduled(MediaJobController::HOOK, [$queued->id]) !== false, 'Queued preparation schedule recovers.');
    for ($i = 0; $i < 100 && ! $c->current()->terminal(); ++$i) {
        ($GLOBALS['test_actions'][MediaJobController::HOOK])($queued->id);
        if (isset($c->current()->checkpoint['phase'])) { $check($created === 0, 'No AWS client while preparing.'); }
    }
    $check($c->current()->status === 'succeeded', 'Cron handler prepares and uploads end to end.');
    $check(isset($remote->objects['test-bucket/prepared/backups/media/' . $queued->id . '/complete.json']), 'Original destination is immutable.');
    $check(wp_next_scheduled(MediaJobController::HOOK, [$queued->id]) === false, 'Completed Cron cleared.');

    // Explicit time-limit failure: no retries forever and no upload/readiness marker.
    $limited = MediaPreparationStep::initialize($source, $base . '/private', ABSPATH) + ['region' => 'test', 'bucket' => 'test', 'prefix' => 'test/'];
    $badStore = new UploadTestStore(); $bad = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $limited);
    $badStore->record = $bad->encode();
    $check((new MediaPreparationStep($source, $limited['directory'], ABSPATH, 2, 0.000000001))->tick($badStore, $bad->id) === 'failed', 'Directory timeout is terminal.');
    $check(BackupJob::decode($badStore->record)->errorCode === 'preparation_requires_cli', 'Safe actionable CLI error code.');
    $check(! file_exists($limited['directory'] . '/ready.json'), 'Timeout never publishes ready.');

    // Empty uploads remains valid; metadata changes fail without skipping entries.
    $GLOBALS['test_upload_root'] = $base . '/empty';
    $emptyStore = new UploadTestStore(); $emptyRemote = new UploadTestS3($base . '/remote');
    $emptyC = new MediaJobController($emptyStore, static fn ($region) => $emptyRemote);
    $empty = $emptyC->enqueuePreparation($base . '/private');
    $check($emptyC->run($empty->id) === 'succeeded', 'Empty preparation and upload succeeds.');
    $changed = MediaPreparationStep::initialize(new MediaSource($base . '/empty'), $base . '/private', ABSPATH);
    file_put_contents($base . '/empty/new-file', 'new');
    $changedStore = new UploadTestStore(); $changedJob = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $changed);
    $changedStore->record = $changedJob->encode();
    $check((new MediaPreparationStep(new MediaSource($base . '/empty'), $changed['directory'], ABSPATH))->tick($changedStore, $changedJob->id) === 'failed', 'Changed directory fails closed.');
    echo 'PASS media preparation checks=' . $checks . ' peak_bytes=' . memory_get_peak_usage(true) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $exit = 1;
} finally {
    // Only the fresh test-owned tree; never follow symlinks or accept caller paths.
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $file) { $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($base);
}
exit($exit);
