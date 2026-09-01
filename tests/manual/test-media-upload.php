<?php

use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;

spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

final class UploadTestStore implements JobStore
{
    public ?string $record = null;
    public function read(): ?string { return $this->record; }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($expected !== $this->record) { return false; }
        $this->record = $replacement;
        return true;
    }
}

/** Disk-backed S3 model checks payloads independently; no network or AWS keys. */
final class UploadTestS3 implements MediaObjectClient
{
    public array $uploads = [];
    public array $objects = [];
    public array $calls = [];
    public ?Closure $after = null;
    public bool $corruptHead = false;
    public bool $badList = false;
    public int $pageSize = 1000;
    public function __construct(private string $directory) {}
    public function request(string $operation, array $a, int $deadline): array
    {
        $this->calls[] = $operation;
        $key = $a['Bucket'] . '/' . $a['Key'];
        $result = [];
        switch ($operation) {
            case 'AbortMultipartUpload':
                $upload = $this->uploads[$a['UploadId']] ?? null;
                if ($upload === null) {
                    $result = ['missing' => true];
                    break;
                }
                if ($upload['key'] !== $key) {
                    throw new RuntimeException('Wrong multipart cleanup target');
                }
                foreach ($upload['parts'] as $part) {
                    if (isset($part['path']) && is_file($part['path'])) { unlink($part['path']); }
                }
                unset($this->uploads[$a['UploadId']]);
                break;
            case 'CreateMultipartUpload':
                $id = bin2hex(random_bytes(8));
                $this->uploads[$id] = ['key' => $key, 'parts' => []];
                $result = ['UploadId' => $id];
                break;
            case 'UploadPart':
                $path = $this->directory . '/part-' . bin2hex(random_bytes(8));
                $out = fopen($path, 'xb');
                fseek($a['Body'], $a['RangeOffset']);
                $remaining = $a['ContentLength'];
                $hash = hash_init('sha256');
                while ($remaining > 0) {
                    $chunk = fread($a['Body'], min(1048576, $remaining));
                    if ($chunk === '') { throw new RuntimeException('Short body'); }
                    fwrite($out, $chunk);
                    hash_update($hash, $chunk);
                    $remaining -= strlen($chunk);
                }
                fclose($out);
                $checksum = base64_encode(hash_final($hash, true));
                if ($checksum !== $a['ChecksumSHA256']) { throw new RuntimeException('BadDigest'); }
                $part = ['PartNumber' => $a['PartNumber'], 'Size' => $a['ContentLength'],
                    'ChecksumSHA256' => $checksum, 'ETag' => 'etag-' . $checksum, 'path' => $path];
                $this->uploads[$a['UploadId']]['parts'][$a['PartNumber']] = $part;
                $result = ['ChecksumSHA256' => $checksum];
                break;
            case 'ListParts':
                $parts = array_values($this->uploads[$a['UploadId']]['parts']);
                usort($parts, static fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
                $parts = array_values(array_filter($parts, static fn ($p) => $p['PartNumber'] > $a['PartNumberMarker']));
                $more = count($parts) > $this->pageSize;
                $parts = array_slice($parts, 0, $this->pageSize);
                foreach ($parts as &$listedPart) {
                    $listedPart['Size'] = (string) $listedPart['Size'];
                }
                unset($listedPart);
                if ($this->badList && $parts !== []) { $parts[0]['ChecksumSHA256'] = 'wrong'; }
                $result = ['Parts' => $parts, 'IsTruncated' => $more,
                    'NextPartNumberMarker' => $parts === [] ? 0 : end($parts)['PartNumber']];
                break;
            case 'CompleteMultipartUpload':
                if (isset($this->objects[$key])) { throw new RuntimeException('Overwrite attempted'); }
                $path = $this->directory . '/object-' . bin2hex(random_bytes(8));
                $out = fopen($path, 'xb');
                $hash = hash_init('sha256');
                foreach ($a['MultipartUpload']['Parts'] as $part) {
                    $remote = $this->uploads[$a['UploadId']]['parts'][$part['PartNumber']];
                    if ($remote['ETag'] !== $part['ETag']) { throw new RuntimeException('Bad ETag'); }
                    $in = fopen($remote['path'], 'rb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    hash_update($hash, base64_decode($remote['ChecksumSHA256']));
                }
                fclose($out);
                $this->objects[$key] = ['path' => $path, 'ContentLength' => filesize($path),
                    'ChecksumSHA256' => base64_encode(hash_final($hash, true)) . '-' . count($a['MultipartUpload']['Parts'])];
                unset($this->uploads[$a['UploadId']]);
                break;
            case 'HeadObject':
                $result = $this->objects[$key] ?? ['missing' => true];
                if ($this->corruptHead && ! isset($result['missing'])) { $result['ChecksumSHA256'] = 'bad'; }
                break;
            case 'PutObject':
                if ($a['IfNoneMatch'] !== '*') { throw new RuntimeException('Conditional write required'); }
                if (isset($this->objects[$key])) { $result = ['exists' => true]; break; }
                if (is_resource($a['Body'])) {
                    fseek($a['Body'], $a['RangeOffset']);
                    $a['Body'] = stream_get_contents($a['Body'], $a['ContentLength']);
                }
                $checksum = base64_encode(hash('sha256', $a['Body'], true));
                if ($checksum !== $a['ChecksumSHA256']) { throw new RuntimeException('BadDigest'); }
                $path = $this->directory . '/object-' . bin2hex(random_bytes(8));
                file_put_contents($path, $a['Body']);
                $this->objects[$key] = ['path' => $path, 'ContentLength' => strlen($a['Body']), 'ChecksumSHA256' => $checksum];
                break;
            default: throw new RuntimeException('Unsupported operation');
        }
        if ($this->after !== null) { ($this->after)($operation); }
        return $result;
    }
}

// Narrow WordPress test doubles, isolated from any running site/database.
class wpdb {
    public string $options = 'test_options';
    public string $last_error = '';
    public function esc_like($text): string { return addcslashes($text, '_%\\'); }
    public function prepare($query, ...$args): string { return $query; }
    public function get_col($query): array {
        return array_slice(array_values(array_filter(array_keys($GLOBALS['test_options']),
            static fn ($name) => str_starts_with($name, 'secure_s3_storage_media_result_'))), 0, 100);
    }
}
function delete_option($name): bool {
    if (! array_key_exists($name, $GLOBALS['test_options'])) { return false; }
    unset($GLOBALS['test_options'][$name]);
    return true;
}
function is_multisite(): bool { return false; }
function wp_get_upload_dir(): array { return ['basedir' => $GLOBALS['test_upload_root']]; }
function get_option($name, $default = false) { return $GLOBALS['test_options'][$name] ?? $default; }
function add_option($name, $value, $deprecated = '', $autoload = false): bool {
    if (isset($GLOBALS['test_options'][$name])) { return false; }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}
function wp_next_scheduled($hook, $args) {
    return $GLOBALS['test_events'][$hook . json_encode($args)]['timestamp'] ?? false;
}
function wp_schedule_event($timestamp, $interval, $hook, $args, $error): bool {
    if ($GLOBALS['test_schedule_failure'] ?? false) { return false; }
    $GLOBALS['test_events'][$hook . json_encode($args)] = [
        'timestamp' => $timestamp, 'recurrence' => $interval,
    ];
    return true;
}
function wp_schedule_single_event($timestamp, $hook, $args, $error): bool {
    if ($GLOBALS['test_schedule_failure'] ?? false) { return false; }
    $GLOBALS['test_events'][$hook . json_encode($args)] = [
        'timestamp' => $timestamp, 'recurrence' => false,
    ];
    return true;
}
function wp_clear_scheduled_hook($hook, $args = []): void { unset($GLOBALS['test_events'][$hook . json_encode($args)]); }
function wp_unschedule_hook($hook): void {
    foreach (array_keys($GLOBALS['test_events']) as $key) {
        if (str_starts_with($key, $hook)) { unset($GLOBALS['test_events'][$key]); }
    }
}
function is_wp_error($value): bool { return false; }
function add_action($hook, $callback, $priority = 10, $accepted = 1): void { $GLOBALS['test_actions'][$hook] = $callback; }
function add_filter($hook, $callback): void { $GLOBALS['test_filters'][$hook] = $callback; }
function delete_transient($name): void {}

// Reuse the disk-backed S3 model and WordPress stubs in preparation integration tests.
if (defined('ODBFS3_UPLOAD_HELPERS_ONLY')) { return; }

$base = sys_get_temp_dir() . '/odbfs3-upload-test-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
mkdir($base . '/uploads', 0700);
mkdir($base . '/remote', 0700);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$newJob = static function (MediaUploadPlan $plan): array {
    $store = new UploadTestStore();
    $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: [
        'directory' => $plan->directory, 'metadata' => $plan->metadata(),
        'region' => 'ap-northeast-1', 'bucket' => 'test-bucket', 'prefix' => 'test/', 'offset' => 0,
    ]);
    $store->record = $job->encode();
    return [$store, $job];
};
$finish = static function ($store, $job, $s3, ?Closure $clock = null): string {
    for ($i = 0; $i < 100; ++$i) {
        // New runner/step per tick: no in-memory cursor or SDK state survives.
        $status = (new JobRunner($store, $clock))->tick($job->id, 'media', new MediaUploadStep($s3));
        if (in_array($status, ['succeeded', 'failed'], true)) { return $status; }
    }
    throw new RuntimeException('Worker did not terminate');
};
$exit = 0;
try {
    $stream = fopen($base . '/uploads/large.bin', 'wb');
    for ($i = 0; $i < 17; ++$i) { fwrite($stream, random_bytes(1048576)); }
    fclose($stream);
    file_put_contents($base . '/uploads/日本語.png', 'image fixture');
    file_put_contents($base . '/uploads/.hidden', 'hidden');
    file_put_contents($base . '/uploads/empty', '');
    $source = new MediaSource($base . '/uploads');
    $plan = MediaUploadPlan::prepare($source, $base);
    $check($plan->metadata()['files'] === 4, 'All media included');
    [$store, $job] = $newJob($plan);
    $s3 = new UploadTestS3($base . '/remote');
    $s3->pageSize = 1;
    $check($finish($store, $job, $s3) === 'succeeded', 'Complete upload');
    $final = BackupJob::decode($store->record);
    $check($final->processedFiles === 4 && $final->processedBytes === $plan->metadata()['bytes'], 'Cumulative counters');
    $prefix = 'test-bucket/test/backups/media/' . $job->id . '/';
    $check(count($s3->objects) === 6 && isset($s3->objects[$prefix . 'complete.json']), 'Marker published last');
    $check(count($s3->uploads) === 0, 'Successful multipart sessions completed');
    mkdir($base . '/restored', 0700);
    $manifest = $s3->objects[$prefix . 'inventory.jsonl']['path'];
    $check(hash_file('sha256', $manifest) === $plan->metadata()['inventory_sha256'], 'Uploaded inventory digest');
    foreach ((new MediaManifest())->entries($manifest) as $entry) {
        copy($s3->objects[$prefix . 'files/' . hash('sha256', $entry->path)]['path'], $base . '/restored/' . $entry->path);
    }
    $check((new MediaManifest())->verify(new MediaSource($base . '/restored'), $manifest, $base)->successful(), 'Download/reassemble and full-file restore verification');

    // Simulate worker death after S3 accepted UploadPart, before checkpoint commit.
    [$store, $job] = $newJob($plan);
    $s3 = new UploadTestS3($base . '/remote');
    $time = 1000;
    $clock = static function () use (&$time): int { return $time; };
    $runner = new JobRunner($store, $clock);
    $step = new MediaUploadStep($s3);
    do {
        $check($runner->tick($job->id, 'media', $step) === 'running', 'Init checkpoint');
    } while (! isset(BackupJob::decode($store->record)->checkpoint['upload_id']));
    $s3->after = static function ($operation) use (&$time): void { if ($operation === 'UploadPart') { $time += 61; } };
    $check($runner->tick($job->id, 'media', $step) === 'lease_lost', 'Reject expired part checkpoint');
    $s3->after = null;
    $check($finish($store, $job, $s3, $clock) === 'succeeded', 'Resume idempotent part after process loss');
    $check(count(array_filter($s3->calls, static fn ($call) => $call === 'UploadPart')) === 4, 'Only lost part resent');

    // Lost complete acknowledgement: HeadObject must recover the verified object.
    [$store, $job] = $newJob($plan);
    $s3 = new UploadTestS3($base . '/remote');
    $once = true;
    $s3->after = static function ($operation) use (&$once): void {
        if ($once && $operation === 'CompleteMultipartUpload') { $once = false; throw new RetryableJobException('safe'); }
    };
    $check($finish($store, $job, $s3) === 'succeeded', 'Lost completion acknowledgement recovered');

    [$store, $job] = $newJob($plan);
    $s3 = new UploadTestS3($base . '/remote');
    $s3->after = static function ($operation): void { throw new RetryableJobException('safe'); };
    $check($finish($store, $job, $s3) === 'failed', 'Retry budget exhausted');
    $check(BackupJob::decode($store->record)->errorCode === 'recovery_exhausted', 'Safe retry failure code');
    $check(! str_contains($store->record, 'safe'), 'No exception text persisted');

    foreach (['head', 'list', 'source'] as $failure) {
        [$store, $job] = $newJob($plan);
        $s3 = new UploadTestS3($base . '/remote');
        $s3->corruptHead = $failure === 'head';
        $s3->badList = $failure === 'list';
        if ($failure === 'source') { file_put_contents($base . '/uploads/.hidden', 'CHANGE'); }
        $check($finish($store, $job, $s3) === 'failed', 'Reject ' . $failure . ' integrity failure');
        $check(! isset($s3->objects['test-bucket/test/backups/media/' . $job->id . '/complete.json']), 'No marker on ' . $failure . ' failure');
    }
    file_put_contents($base . '/uploads/.hidden', 'hidden');
    [$store, $job] = $newJob($plan);
    $s3 = new UploadTestS3($base . '/remote');
    $time = 2000;
    $runner = new JobRunner($store, static fn (): int => 2000);
    $claimed = $job->claim($time, 60);
    $store->record = $claimed->encode();
    $check($runner->tick($job->id, 'media', new MediaUploadStep($s3)) === 'busy' && $s3->calls === [], 'Concurrent worker does no I/O');

    mkdir($base . '/empty-uploads', 0700);
    $emptyPlan = MediaUploadPlan::prepare(new MediaSource($base . '/empty-uploads'), $base);
    [$store, $job] = $newJob($emptyPlan);
    $s3 = new UploadTestS3($base . '/remote');
    $check($finish($store, $job, $s3) === 'succeeded', 'Empty uploads still publishes verified inventory');
    mkdir($base . '/web', 0700);
    define('ABSPATH', $base . '/web/');
    $GLOBALS['test_upload_root'] = $base . '/uploads';
    $GLOBALS['test_options'] = ['secure_s3_storage_settings' => ['region' => 'ap-northeast-1', 'bucket' => 'test-bucket', 'prefix' => 'test/']];
    $GLOBALS['test_events'] = [];
    $store = new UploadTestStore();
    $s3 = new UploadTestS3($base . '/remote');
    $controller = new \SecureS3StorageForWordpress\WordPress\MediaJobController($store, static fn ($region) => $s3);
    $controller->register();
    $check(isset($GLOBALS['test_actions'][\SecureS3StorageForWordpress\WordPress\MediaJobController::HOOK]), 'Cron handler registered');
    $check(! isset($GLOBALS['test_filters']['cron_schedules']), 'No recurring worker interval registered');
    $queued = $controller->start($plan);
    $check(count($GLOBALS['test_events']) === 1 && $queued->status === 'queued', 'Submission schedules durable job');
    $eventKey = \SecureS3StorageForWordpress\WordPress\MediaJobController::HOOK . json_encode([$queued->id]);
    $check($GLOBALS['test_events'][$eventKey]['recurrence'] === false
        && $GLOBALS['test_events'][$eventKey]['timestamp'] >= time() + 4
        && $GLOBALS['test_events'][$eventKey]['timestamp'] <= time() + 6,
        'Submission uses a near-term single event');
    $controller->recoverSchedule();
    $check(count($GLOBALS['test_events']) === 1, 'No duplicate event on init');
    $GLOBALS['test_options']['secure_s3_storage_settings']['bucket'] = 'changed-bucket';
    $check($controller->current()->checkpoint['bucket'] === 'test-bucket', 'Destination snapshot does not follow settings edits');
    try { $controller->start($plan); throw new LogicException('Duplicate accepted'); }
    catch (RuntimeException $e) { $check(true, 'Concurrent submission rejected'); }
    \SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::deactivate();
    $check($GLOBALS['test_events'] === [] && $controller->current()->status === 'queued', 'Deactivation clears dispatch but preserves job');
    $controller->recoverSchedule();
    $check(count($GLOBALS['test_events']) === 1, 'Reactivation/init restores dispatch');
    $calls = count($s3->calls);
    $check($controller->run(str_repeat('f', 32)) === 'mismatch' && count($s3->calls) === $calls, 'Stale Cron event does not send');
    // Model the former recurring event already having scheduled its next run.
    // A returned batch must migrate it to a completion-paced single event.
    $GLOBALS['test_events'][$eventKey] = [
        'timestamp' => time() + 60, 'recurrence' => 'former-worker-minute',
    ];
    $store->record = $queued->claim(time(), 60)->encode();
    $check($controller->run($queued->id) === 'busy', 'A contended batch remains nonterminal');
    $check(($GLOBALS['test_events'][$eventKey]['recurrence'] ?? null) === false
        && $GLOBALS['test_events'][$eventKey]['timestamp'] >= time() + 4
        && $GLOBALS['test_events'][$eventKey]['timestamp'] <= time() + 6,
        'A returned nonterminal batch chains a near-term single event');
    $store->record = $queued->encode();
    wp_clear_scheduled_hook(\SecureS3StorageForWordpress\WordPress\MediaJobController::HOOK, [$queued->id]);
    $unavailable = new \SecureS3StorageForWordpress\WordPress\MediaJobController(
        $store,
        static function (): never { throw new RuntimeException('test-only worker failure'); },
    );
    $check($unavailable->run($queued->id) === 'worker_unavailable', 'Unexpected worker failure stays generic');
    $check(($GLOBALS['test_events'][$eventKey]['recurrence'] ?? null) === false
        && $GLOBALS['test_events'][$eventKey]['timestamp'] >= time() + 59
        && $GLOBALS['test_events'][$eventKey]['timestamp'] <= time() + 61,
        'Unexpected process failure leaves a delayed recovery event');
    wp_clear_scheduled_hook(\SecureS3StorageForWordpress\WordPress\MediaJobController::HOOK, [$queued->id]);
    $check($controller->run($queued->id) === 'succeeded', 'Cron processes bounded batch end to end');
    $check($GLOBALS['test_events'] === [], 'Terminal job unscheduled');
    $GLOBALS['test_schedule_failure'] = true;
    try { $controller->start($plan); throw new LogicException('Scheduling failure hidden'); }
    catch (RuntimeException $e) { $check(true, 'Scheduling failure reported'); }
    $check($controller->current()->status === 'queued', 'Scheduling failure keeps recoverable queue');
    $check(isset($GLOBALS['test_options']['secure_s3_storage_media_result_' . $queued->id]), 'Previous terminal result archived before replacement');
    $GLOBALS['test_schedule_failure'] = false;
    $controller->recoverSchedule();
    $check(count($GLOBALS['test_events']) === 1, 'Scheduler failure recovered');

    // Alter an otherwise valid prepared descriptor: final chain must prevent completion.
    $changedPlan = MediaUploadPlan::prepare(new MediaSource($base . '/empty-uploads'), $base);
    [$changedStore, $changedJob] = $newJob($changedPlan);
    $raw = file_get_contents($changedPlan->directory . '/objects.jsonl');
    file_put_contents($changedPlan->directory . '/objects.jsonl', str_replace('"path":null', '"path":null ', $raw));
    $changedS3 = new UploadTestS3($base . '/remote');
    $check($finish($changedStore, $changedJob, $changedS3) === 'failed', 'Changed plan never completes');
    $check(! isset($changedS3->objects['test-bucket/test/backups/media/' . $changedJob->id . '/complete.json']), 'No marker for changed plan');
    $GLOBALS['wpdb'] = new wpdb();
    $GLOBALS['test_options']['secure_s3_storage_background_job'] = $store->record;
    $GLOBALS['test_options']['unrelated_plugin_option'] = 'keep';
    $remoteCount = count($s3->objects);
    \SecureS3StorageForWordpress\Lifecycle\PluginLifecycle::uninstall();
    $check($GLOBALS['test_events'] === [], 'Uninstall removes worker events');
    $check(! isset($GLOBALS['test_options']['secure_s3_storage_background_job'])
        && ! isset($GLOBALS['test_options']['secure_s3_storage_media_result_' . $queued->id]), 'Uninstall removes current and archived local job metadata');
    $check($GLOBALS['test_options']['unrelated_plugin_option'] === 'keep'
        && count($s3->objects) === $remoteCount && is_file($plan->directory . '/inventory.jsonl'), 'Uninstall preserves unrelated options, remote backups and private plans');
    echo 'PASS media upload checks=' . $checks . ' peak_bytes=' . memory_get_peak_usage(true) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getLine() . PHP_EOL);
    $exit = 1;
} finally {
    // Only files created under this random test-owned directory are removed.
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $file) { $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($base);
}
exit($exit);
