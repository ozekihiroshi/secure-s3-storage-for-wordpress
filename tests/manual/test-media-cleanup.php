<?php

use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Media\MediaFailedJobCleanup;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\WordPress\MediaJobController;

define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
require __DIR__ . '/test-media-upload.php';

$base = sys_get_temp_dir() . '/odbfs3-cleanup-test-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
mkdir($base . '/web', 0700);
mkdir($base . '/uploads', 0700);
mkdir($base . '/remote', 0700);
mkdir($base . '/fixture-evidence', 0700);
mkdir($base . '/restore-evidence', 0700);
file_put_contents($base . '/uploads/large.bin', str_repeat('a', 9 * 1024 * 1024));
file_put_contents($base . '/fixture-evidence/keep.txt', 'fixture');
file_put_contents($base . '/restore-evidence/keep.txt', 'restore');

define('ABSPATH', $base . '/web/');
$GLOBALS['test_upload_root'] = $base . '/uploads';
$GLOBALS['test_options'] = ['secure_s3_storage_settings' => [
    'region' => 'ap-northeast-1', 'bucket' => 'test-bucket', 'prefix' => 'test/',
]];
$GLOBALS['test_events'] = [];
$GLOBALS['test_actions'] = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$rejects = static function (Closure $operation, string $message) use ($check): void {
    try { $operation(); } catch (RuntimeException $e) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$source = new MediaSource($base . '/uploads');
$newPlan = static fn (): MediaUploadPlan => MediaUploadPlan::prepare($source, $base);
$identity = static fn (MediaUploadPlan $plan): array => MediaFailedJobCleanup::captureIdentity(
    $plan->directory,
    [ABSPATH, $source->rootPath()],
);
$failed = static function (MediaUploadPlan $plan, string $id, array $extra = []) use ($identity): BackupJob {
    return new BackupJob($id, 'media', 'failed', [
        'directory' => $plan->directory, 'metadata' => $plan->metadata(),
        'work_identity' => $identity($plan), 'region' => 'ap-northeast-1',
        'bucket' => 'test-bucket', 'prefix' => 'test/', 'offset' => 0,
    ] + $extra, errorCode: 'step_failed');
};
$removeWorkspaceForCrash = static function (string $directory): void {
    foreach (scandir($directory) as $name) {
        if ($name !== '.' && $name !== '..') { unlink($directory . '/' . $name); }
    }
    rmdir($directory);
};

$exit = 0;
try {
    $plan = $newPlan();
    $id = bin2hex(random_bytes(16));
    $key = 'test/backups/media/' . $id . '/files/' . hash('sha256', 'large.bin');
    $s3 = new UploadTestS3($base . '/remote');
    $upload = $s3->request('CreateMultipartUpload', [
        'Bucket' => 'test-bucket', 'Key' => $key,
    ], time() + 30);
    $sentinelPath = $base . '/remote/completed-object';
    file_put_contents($sentinelPath, 'completed');
    $s3->objects['test-bucket/test/backups/media/other/complete.json'] = [
        'path' => $sentinelPath, 'ContentLength' => 9, 'ChecksumSHA256' => 'sentinel',
    ];
    $store = new UploadTestStore();
    $store->record = $failed($plan, $id, [
        'upload_id' => $upload['UploadId'], 'upload_key' => $key,
    ])->encode();
    $controller = new MediaJobController($store, static fn ($region) => $s3);

    $beforeCalls = count($s3->calls);
    $rejects(static fn () => $controller->cleanupFailedJob(str_repeat('f', 32)),
        'Wrong job ID is rejected.');
    $check(count($s3->calls) === $beforeCalls && is_dir($plan->directory),
        'Wrong ID performs no S3 or filesystem cleanup.');

    $result = $controller->cleanupFailedJob($id);
    $check($result['state'] === 'completed' && $result['multipart_aborted_or_missing'],
        'Recorded multipart and private work cleanup completes.');
    $check(! is_dir($plan->directory) && $s3->uploads === [],
        'Only the exact failed workspace and multipart are removed.');
    $check(isset($s3->objects['test-bucket/test/backups/media/other/complete.json'])
        && file_get_contents($sentinelPath) === 'completed', 'Completed S3 objects are retained.');
    $check(file_get_contents($base . '/fixture-evidence/keep.txt') === 'fixture'
        && file_get_contents($base . '/restore-evidence/keep.txt') === 'restore',
        'Fixture and restore evidence are retained.');
    $saved = BackupJob::decode($store->record);
    $check(array_keys($saved->checkpoint) === ['cleanup']
        && ! isset($saved->checkpoint['cleanup']['directory'])
        && ! isset($saved->checkpoint['cleanup']['upload_id'])
        && ! isset($saved->checkpoint['cleanup']['key']),
        'Completed record is sanitized and retains no local path or multipart identifier.');
    $calls = count($s3->calls);
    $check($controller->cleanupFailedJob($id) === $result && count($s3->calls) === $calls,
        'Repeated completed cleanup is an I/O-free no-op.');
    $check(! in_array('DeleteObject', $s3->calls, true), 'Cleanup never calls DeleteObject.');

    // Crash after the durable pending CAS and S3 abort, before local deletion.
    $plan = $newPlan();
    file_put_contents($plan->directory . '/unexpected.txt', 'do not delete');
    chmod($plan->directory . '/unexpected.txt', 0600);
    $id = bin2hex(random_bytes(16));
    $key = 'test/backups/media/' . $id . '/inventory.jsonl';
    $s3 = new UploadTestS3($base . '/remote');
    $upload = $s3->request('CreateMultipartUpload', [
        'Bucket' => 'test-bucket', 'Key' => $key,
    ], time() + 30);
    $store = new UploadTestStore();
    $store->record = $failed($plan, $id, [
        'upload_id' => $upload['UploadId'], 'upload_key' => $key,
    ])->encode();
    $controller = new MediaJobController($store, static fn ($region) => $s3);
    $rejects(static fn () => $controller->cleanupFailedJob($id),
        'Unexpected workspace entry fails closed.');
    $pending = BackupJob::decode($store->record);
    $check(($pending->checkpoint['cleanup']['state'] ?? null) === 'pending'
        && is_file($plan->directory . '/unexpected.txt'),
        'Failed cleanup remains durably pending and preserves unexpected data.');
    $other = $newPlan();
    $rejects(static fn () => $controller->start($other),
        'New submission cannot archive a pending cleanup.');
    $before = glob($base . '/odbfs3-preparation-*');
    $rejects(static fn () => $controller->enqueuePreparation($base),
        'Pending cleanup is rejected before background preparation initialization.');
    $after = glob($base . '/odbfs3-preparation-*');
    $check($before === $after, 'Pending cleanup rejection creates no private workspace.');
    $removeWorkspaceForCrash($plan->directory);
    $calls = count($s3->calls);
    $result = $controller->cleanupFailedJob($id);
    $check($result['state'] === 'completed' && count($s3->calls) === $calls + 1,
        'Pending cleanup resumes after abort acknowledgement and missing workspace.');
    $check($s3->calls[array_key_last($s3->calls)] === 'AbortMultipartUpload',
        'Resume treats an already-missing exact multipart as success.');
    $check(is_dir($other->directory), 'A rejected replacement plan is never cleaned as the failed job.');

    // An active owner blocks cleanup until the explicit operation is retried.
    $plan = $newPlan();
    file_put_contents($plan->directory . '/worker.lock', '');
    chmod($plan->directory . '/worker.lock', 0600);
    $lock = fopen($plan->directory . '/worker.lock', 'rb');
    flock($lock, LOCK_EX);
    $id = bin2hex(random_bytes(16));
    $store = new UploadTestStore();
    $store->record = $failed($plan, $id)->encode();
    $controller = new MediaJobController($store, static fn ($region) => new UploadTestS3($base . '/remote'));
    $rejects(static fn () => $controller->cleanupFailedJob($id), 'Locked workspace is not removed.');
    $check(is_dir($plan->directory), 'Locked workspace remains intact.');
    flock($lock, LOCK_UN);
    fclose($lock);
    $controller->cleanupFailedJob($id);
    $check(! is_dir($plan->directory), 'Unlocked pending workspace can be retried safely.');

    // A link is never followed, even when its name is otherwise allowlisted.
    $plan = $newPlan();
    symlink($base . '/fixture-evidence/keep.txt', $plan->directory . '/worker.lock');
    $id = bin2hex(random_bytes(16));
    $store = new UploadTestStore();
    $store->record = $failed($plan, $id)->encode();
    $controller = new MediaJobController($store, static fn ($region) => new UploadTestS3($base . '/remote'));
    $rejects(static fn () => $controller->cleanupFailedJob($id), 'Symlink entry is rejected.');
    $check(file_get_contents($base . '/fixture-evidence/keep.txt') === 'fixture',
        'Symlink target is untouched.');
    unlink($plan->directory . '/worker.lock');
    $controller->cleanupFailedJob($id);

    // Identity must be captured before cleanup; changed or fabricated records fail before CAS.
    $plan = $newPlan();
    $id = bin2hex(random_bytes(16));
    $job = $failed($plan, $id);
    $state = $job->checkpoint;
    ++$state['work_identity']['ino'];
    $store = new UploadTestStore();
    $store->record = (new BackupJob($id, 'media', 'failed', $state,
        errorCode: 'step_failed'))->encode();
    $controller = new MediaJobController($store, static fn ($region) => new UploadTestS3($base . '/remote'));
    $original = $store->record;
    $rejects(static fn () => $controller->cleanupFailedJob($id), 'Workspace identity mismatch is rejected.');
    $check($store->record === $original && is_dir($plan->directory),
        'Identity mismatch performs no durable transition or deletion.');

    $succeededPlan = $newPlan();
    $id = bin2hex(random_bytes(16));
    $store = new UploadTestStore();
    $store->record = (new BackupJob($id, 'media', 'succeeded', [
        'directory' => $succeededPlan->directory,
    ]))->encode();
    $controller = new MediaJobController($store, static fn ($region) => new UploadTestS3($base . '/remote'));
    $rejects(static fn () => $controller->cleanupFailedJob($id), 'Succeeded job cannot be cleaned.');
    $check(is_dir($succeededPlan->directory), 'Succeeded backup work is retained.');

    fwrite(STDOUT, "Media cleanup tests passed: {$checks} checks.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    $exit = 1;
}
exit($exit);
