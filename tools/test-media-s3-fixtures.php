<?php

// Explicit real-AWS fixture test. Never bootstrap WordPress or modify its database.
// Usage: php SCRIPT SOURCE_DIRECTORY prepare|tick|restore WORK_DIRECTORY [FIXTURE_DIRECTORY]
if (PHP_SAPI !== 'cli') { exit; }
if ($argc < 4 || ! in_array($argv[2], ['prepare', 'tick', 'restore'], true)) {
    fwrite(STDERR, "Usage: php SCRIPT SOURCE_DIRECTORY prepare|tick|restore WORK_DIRECTORY [FIXTURE_DIRECTORY]\n");
    exit(1);
}
require $argv[1] . '/vendor/autoload.php';

use Aws\S3\S3Client;
use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use SecureS3StorageForWordpress\Backup\Media\MediaEntry;
use SecureS3StorageForWordpress\Backup\Media\MediaInventoryIO;
use SecureS3StorageForWordpress\Backup\Media\MediaInventorySorter;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;

/** Test-only disk CAS; private directory + flock + atomic replacement across PHP processes. */
final class FixtureDiskJobStore implements JobStore
{
    public function __construct(private string $directory) {}
    public function read(): ?string
    {
        $file = $this->directory . '/job.json';
        if (! file_exists($file)) { return null; }
        $value = file_get_contents($file);
        if ($value === false) { throw new RuntimeException('Cannot read test checkpoint.'); }
        return $value;
    }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        $lock = fopen($this->directory . '/job.lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock test checkpoint.'); }
        try {
            if ($this->read() !== $expected) { return false; }
            $temporary = $this->directory . '/checkpoint-' . bin2hex(random_bytes(8));
            $stream = SecureTemporaryFile::openForWriting($temporary);
            try { MediaInventoryIO::write($stream, $replacement); fflush($stream); fsync($stream); }
            finally { fclose($stream); }
            if (! rename($temporary, $this->directory . '/job.json')) { throw new RuntimeException('Cannot commit test checkpoint.'); }
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Records only operation identifiers, not credentials, bodies, signed requests or exceptions. */
final class FixtureRecordingClient implements MediaObjectClient
{
    public function __construct(private MediaObjectClient $client, private string $directory) {}
    public function request(string $operation, array $arguments, int $deadline): array
    {
        $record = ['time' => gmdate('c'), 'operation' => $operation, 'key' => $arguments['Key'] ?? null];
        try {
            $result = $this->client->request($operation, $arguments, $deadline);
            $record['upload_id'] = $result['UploadId'] ?? $arguments['UploadId'] ?? null;
            $record['part'] = $arguments['PartNumber'] ?? null;
            $record['result'] = 'success';
            return $result;
        } catch (Throwable $e) {
            $record['result'] = 'failed';
            throw $e;
        } finally {
            $stream = fopen($this->directory . '/operations.jsonl', 'ab');
            if ($stream === false || ! flock($stream, LOCK_EX)) { throw new RuntimeException('Cannot record test operation.'); }
            try { MediaInventoryIO::write($stream, json_encode($record, JSON_THROW_ON_ERROR) . "\n"); fflush($stream); fsync($stream); }
            finally { flock($stream, LOCK_UN); fclose($stream); }
        }
    }
}

function downloadFixtureObject(S3Client $client, string $bucket, string $key, string $destination): void
{
    $stream = SecureTemporaryFile::openForWriting($destination);
    try {
        $result = $client->getObject(['Bucket' => $bucket, 'Key' => $key,
            '@http' => ['sink' => $stream, 'connect_timeout' => 5, 'timeout' => 120]]);
        if (is_resource($stream)) { fflush($stream); }
        clearstatcache(true, $destination);
        if (filesize($destination) !== $result['ContentLength']) { throw new RuntimeException('Incomplete download.'); }
    } finally { if (is_resource($stream)) { fclose($stream); } }
}

try {
    umask(0077);
    $work = realpath($argv[3]);
    if ($work === false || is_link($argv[3]) || ! is_dir($work)
        || (fileperms($work) & 0077) !== 0
        || ! str_starts_with($work, '/tmp/odbfs3-aws-run.')) {
        throw new RuntimeException('Use a new private /tmp/odbfs3-aws-run.* test directory.');
    }
    $store = new FixtureDiskJobStore($work);
    if ($argv[2] === 'prepare') {
        if ($store->read() !== null) { throw new RuntimeException('Test job already exists.'); }
        $fixture = realpath($argv[4] ?? '');
        if ($fixture === false || ! str_starts_with($fixture, '/tmp/odbfs3-media-test.')) {
            throw new RuntimeException('Only synthetic test fixtures are accepted.');
        }
        $info = json_decode(file_get_contents($fixture . '/fixture-info.json'), true, 8, JSON_THROW_ON_ERROR);
        if (($info['format'] ?? '') !== 'odbfs3-synthetic-fixture' || ($info['version'] ?? null) !== 1
            || ! hash_equals($info['expected_sha256'], hash_file('sha256', $fixture . '/expected.jsonl'))) {
            throw new RuntimeException('Invalid synthetic fixture.');
        }
        $started = microtime(true);
        $plan = MediaUploadPlan::prepare(new MediaSource($fixture . '/uploads'), $work);
        $metadata = $plan->metadata();
        $expected = static function () use ($fixture): Generator {
            $stream = MediaInventoryIO::openRead($fixture . '/expected.jsonl');
            try { while (($line = MediaInventoryIO::readLine($stream)) !== null) { yield MediaEntry::decode($line); } }
            finally { fclose($stream); }
        };
        $sorted = (new MediaInventorySorter())->sorted($expected(), $work);
        $actual = (new MediaManifest())->entries($plan->directory . '/inventory.jsonl');
        while ($sorted->valid() || $actual->valid()) {
            if (! $sorted->valid() || ! $actual->valid() || $sorted->current()->encode() !== $actual->current()->encode()) {
                throw new RuntimeException('Prepared inventory differs from original fixture checksums.');
            }
            $sorted->next(); $actual->next();
        }
        if ($metadata['files'] !== $info['files'] || $metadata['bytes'] !== $info['bytes']) {
            throw new RuntimeException('Fixture totals differ.');
        }
        $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: [
            'directory' => $plan->directory, 'metadata' => $metadata,
            'region' => 'ap-northeast-1', 'bucket' => 'ceri-secure-s3-storage-test',
            'prefix' => 'wordpress-test/media-transfer-test/', 'offset' => 0,
        ]);
        if (! $store->compareAndSwap(null, $job->encode())) { throw new RuntimeException('Test slot conflict.'); }
        echo 'job_id=' . $job->id . PHP_EOL . 'plan=' . $plan->directory . PHP_EOL;
        echo 'prepare_seconds=' . round(microtime(true) - $started, 3) . PHP_EOL;
        echo 'files=' . $metadata['files'] . PHP_EOL . 'bytes=' . $metadata['bytes'] . PHP_EOL;
        echo "result=prepared_and_matches_original_fixture\n";
    } elseif ($argv[2] === 'tick') {
        $job = BackupJob::decode($store->read() ?? '');
        $step = new MediaUploadStep(new FixtureRecordingClient(MediaS3Client::create($job->checkpoint['region']), $work));
        $started = microtime(true);
        $runner = new JobRunner($store);
        for ($i = 0; $i < 100 && microtime(true) - $started < 20; ++$i) {
            $status = $runner->tick($job->id, 'media', $step);
            $now = BackupJob::decode($store->read());
            if ($status !== 'running' || $now->attempts > 0) { break; }
        }
        echo json_encode(['status' => $status, 'files' => $now->processedFiles,
            'bytes' => $now->processedBytes, 'part' => $now->checkpoint['part'] ?? null,
            'error' => $now->errorCode, 'seconds' => round(microtime(true) - $started, 3),
            'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($status === 'succeeded' ? 0 : ($status === 'running' ? 2 : 1));
    } else {
        $job = BackupJob::decode($store->read() ?? '');
        if ($job->status !== 'succeeded') { throw new RuntimeException('Cannot restore an incomplete run.'); }
        $state = $job->checkpoint;
        $prefix = $state['prefix'] . 'backups/media/' . $job->id . '/';
        $client = new S3Client(['version' => 'latest', 'region' => $state['region'], 'retries' => 0]);
        $restore = $work . '/restore-' . bin2hex(random_bytes(8));
        if (! mkdir($restore, 0700)) { throw new RuntimeException('Cannot create empty restore directory.'); }
        $manifest = $work . '/downloaded-inventory-' . bin2hex(random_bytes(8)) . '.jsonl';
        $marker = $work . '/downloaded-complete-' . bin2hex(random_bytes(8)) . '.json';
        downloadFixtureObject($client, $state['bucket'], $prefix . 'complete.json', $marker);
        if (filesize($marker) > 8192) { throw new RuntimeException('Oversized completion marker.'); }
        $completion = json_decode(file_get_contents($marker), true, 8, JSON_THROW_ON_ERROR);
        if (($completion['format'] ?? '') !== 'odbfs3-media-complete' || ($completion['version'] ?? null) !== 1
            || ($completion['run'] ?? '') !== $job->id || ($completion['inventory'] ?? '') !== 'inventory.jsonl'
            || ($completion['files'] ?? null) !== $job->processedFiles || ($completion['bytes'] ?? null) !== $job->processedBytes
            || ($completion['inventory_sha256'] ?? '') !== $state['metadata']['inventory_sha256']
            || ($completion['object_key_rule'] ?? '') !== 'files/sha256(UTF-8 relative path)') {
            throw new RuntimeException('Unexpected completion marker.');
        }
        downloadFixtureObject($client, $state['bucket'], $prefix . 'inventory.jsonl', $manifest);
        if (! hash_equals($completion['inventory_sha256'], hash_file('sha256', $manifest))) {
            throw new RuntimeException('Downloaded manifest digest differs.');
        }
        $count = $bytes = 0;
        foreach ((new MediaManifest())->entries($manifest) as $entry) { ++$count; $bytes += $entry->size; }
        if ($count !== $job->processedFiles || $bytes !== $job->processedBytes) { throw new RuntimeException('Manifest totals differ.'); }
        $started = microtime(true);
        $done = 0;
        foreach ((new MediaManifest())->entries($manifest) as $entry) {
            $destination = $restore . '/' . $entry->path;
            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
                throw new RuntimeException('Cannot create restore subdirectory.');
            }
            downloadFixtureObject($client, $state['bucket'], $prefix . 'files/' . hash('sha256', $entry->path), $destination);
            if (filesize($destination) !== $entry->size || ! hash_equals($entry->sha256, hash_file('sha256', $destination))) {
                throw new RuntimeException('Restored file checksum differs.');
            }
            if (++$done % 250 === 0) { echo 'downloaded_files=' . $done . PHP_EOL; }
        }
        $verification = (new MediaManifest())->verify(new MediaSource($restore), $manifest, $work);
        if (! $verification->successful()) { throw new RuntimeException('Independent restored inventory differs.'); }
        $report = ['result' => 's3_restore_matches_original_fixture', 'job_id' => $job->id,
            'files' => $count, 'bytes' => $bytes, 'restore_directory' => $restore,
            's3_prefix' => 's3://' . $state['bucket'] . '/' . $prefix,
            'restore_seconds' => round(microtime(true) - $started, 3), 'peak_bytes' => memory_get_peak_usage(true)];
        $stream = SecureTemporaryFile::openForWriting($work . '/result-' . bin2hex(random_bytes(8)) . '.json');
        try { MediaInventoryIO::write($stream, json_encode($report, JSON_THROW_ON_ERROR) . "\n"); }
        finally { fclose($stream); }
        echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'test_failed=' . get_class($e) . PHP_EOL);
    // Show safe AWS error codes only, never raw exception messages or request headers.
    if (method_exists($e, 'getAwsErrorCode')) { fwrite(STDERR, 'aws_code=' . $e->getAwsErrorCode() . PHP_EOL); }
    if (method_exists($e, 'getStatusCode')) { fwrite(STDERR, 'http_status=' . $e->getStatusCode() . PHP_EOL); }
    exit(1);
}
