<?php

// EC2 test bridge. Synthetic package only; no WordPress bootstrap or DB access.
require __DIR__ . '/../vendor/autoload.php';

use SecureS3StorageForWordpress\Aws\{S3ClientFactory, MediaS3Client};
use SecureS3StorageForWordpress\Backup\Job\{JobStore, RetryableJobException};
use SecureS3StorageForWordpress\Backup\Media\{MediaObjectClient, MediaManifest};
use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet, RecoveryManifest, RecoveryWorkspace, RecoveryCollector, RecoveryPublisher, RecoveryStep, RecoveryProgress};

final class S3FixtureStore implements JobStore
{
    public function __construct(private string $path) {}
    public function read(): ?string { return is_file($this->path) ? file_get_contents($this->path) : null; }
    public function compareAndSwap(?string $expected, string $replacement): bool {
        if ($this->read() !== $expected) { return false; }
        file_put_contents($this->path . '.new', $replacement);
        return rename($this->path . '.new', $this->path);
    }
}
final class S3FixtureEvidence implements RecoveryStep
{
    public function __construct(private array $db, private array $media) {}
    public function execute(RecoverySet $set, string $phase, array $checkpoint, int $deadline): RecoveryProgress {
        return new RecoveryProgress([], $phase === 'database' ? $this->db : $this->media);
    }
}
final class LostManifestAcknowledgement implements MediaObjectClient
{
    public int $puts = 0;
    public function __construct(private MediaObjectClient $inner) {}
    public function request(string $operation, array $arguments, int $deadline): array {
        $result = $this->inner->request($operation, $arguments, $deadline);
        if ($operation === 'PutObject') { ++$this->puts; throw new RetryableJobException('Test acknowledgement loss after real PUT.'); }
        return $result;
    }
}
function requireFixture(bool $condition): void { if (! $condition) { throw new RuntimeException('Fixture verification failed.'); } }

$phase = 'input';
try {
    requireFixture(PHP_SAPI === 'cli' && $argc === 4 && preg_match('/^[a-f0-9]{12}$/D', $argv[2]) && in_array($argv[3], ['native', 'php'], true));
    $work = realpath($argv[1]);
    requireFixture(is_string($work) && preg_match('#^/tmp/odbfs3-wp-s3\.[A-Za-z0-9]{8}/fixture$#D', $work));
    umask(0077);
    $locator = json_decode(file_get_contents($work . '/locator.json'), true, 8, JSON_THROW_ON_ERROR);
    $sourcePath = static function (string $key) use ($work, $locator): string {
        $path = $work . '/objects/' . hash('sha256', $locator['bucket'] . '/' . $key);
        requireFixture(! is_link($path) && is_file($path) && filesize($path) <= 16777216);
        return $path;
    };
    $oldBytes = file_get_contents($sourcePath($locator['key']));
    requireFixture(strlen($oldBytes) === $locator['size'] && hash('sha256', $oldBytes) === $locator['sha256']);
    $old = RecoveryManifest::decode($oldBytes)->toArray();
    requireFixture($locator['bucket'] === 'fixture-bucket' && $old['destination']['bucket'] === 'fixture-bucket'
        && $old['destination']['prefix'] === 'wordpress' && $old['source_id'] === str_repeat('a', 32)
        && $old['environment']['table_prefix'] === 'fx_' && $old['environment']['plugin'] === 'development'
        && $old['profile'] === 'database_uploads' && $old['database']['backend'] === $argv[3]);
    $bucket = 'ceri-secure-s3-storage-test'; $region = 'ap-northeast-1';
    $prefix = 'wordpress-test/recovery-integrity/' . $argv[2] . '/' . $argv[3];
    $set = RecoverySet::intent($old['set_id'], $old['source_id'], $old['profile'], $bucket, $region, $prefix, $old['created_at']);
    $sdk = (new S3ClientFactory())->create($region);
    $client = new MediaS3Client($sdk);
    $phase = 'permissions';
    $sdk->headBucket(['Bucket' => $bucket]);
    $listing = $sdk->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $prefix . '/', 'MaxKeys' => 1]);
    requireFixture(count($listing['Contents'] ?? []) === 0);
    $report = ['run' => $argv[2], 'backend' => $argv[3], 'bucket' => $bucket, 'prefix' => $prefix,
        'transport' => 'real_s3', 'synthetic_only' => true, 'objects' => [], 'php' => PHP_VERSION];
    foreach (['getBucketVersioning', 'getBucketEncryption', 'getPublicAccessBlock'] as $operation) {
        try { $settings = $sdk->$operation(['Bucket' => $bucket])->toArray(); unset($settings['@metadata']); $report['bucket_checks'][$operation] = $settings; }
        catch (\Aws\Exception\AwsException $e) { $report['bucket_checks'][$operation] = ['unavailable' => $e->getAwsErrorCode()]; }
    }
    $upload = static function (string $oldKey, string $newKey, int $size, string $hash) use ($sourcePath, $client, $bucket, &$report): array {
        $path = $sourcePath($oldKey);
        requireFixture(filesize($path) === $size && hash_file('sha256', $path) === $hash);
        $body = fopen($path, 'rb');
        try {
            $client->request('PutObject', ['Bucket' => $bucket, 'Key' => $newKey, 'Body' => $body, 'RangeOffset' => 0,
                'ContentLength' => $size, 'ChecksumSHA256' => base64_encode(hex2bin($hash)), 'IfNoneMatch' => '*'], time() + 60);
        } finally { fclose($body); }
        $head = $client->request('HeadObject', ['Bucket' => $bucket, 'Key' => $newKey, 'ChecksumMode' => 'ENABLED'], time() + 60);
        requireFixture(($head['ContentLength'] ?? null) === $size && ($head['ChecksumSHA256'] ?? null) === base64_encode(hex2bin($hash)));
        $a = ['key' => $newKey, 'size_bytes' => $size, 'sha256' => $hash, 'version_id' => $head['VersionId'] ?? null];
        $report['objects'][] = $a + ['encryption' => $head['ServerSideEncryption'] ?? 'not-reported'];
        return $a;
    };
    $db = $old['database']; $media = $old['media'];
    $phase = 'database_upload'; $db['transfer']['start'] = gmdate('Y-m-d\TH:i:s\Z');
    $a = $db['artifact']; $db['artifact'] = $upload($a['key'], $set->databaseKey(), $a['size_bytes'], $a['sha256']);
    $db['transfer']['end'] = $db['verified_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $phase = 'media_upload'; $media['transfer']['start'] = gmdate('Y-m-d\TH:i:s\Z');
    $inventory = $sourcePath($media['inventory']['key']);
    requireFixture(hash_file('sha256', $inventory) === $media['inventory']['sha256']);
    $entries = iterator_to_array((new MediaManifest())->entries($inventory));
    requireFixture(count($entries) === $media['file_count']);
    $newMediaPrefix = $set->mediaPrefix($media['job_id']);
    foreach ($entries as $entry) {
        $suffix = 'files/' . hash('sha256', $entry->path);
        $upload($media['prefix'] . $suffix, $newMediaPrefix . $suffix, $entry->size, $entry->sha256);
    }
    foreach (['inventory' => 'inventory.jsonl', 'marker' => 'complete.json'] as $name => $suffix) {
        $a = $media[$name]; $media[$name] = $upload($a['key'], $newMediaPrefix . $suffix, $a['size_bytes'], $a['sha256']);
    }
    $media['prefix'] = $newMediaPrefix;
    $media['transfer']['end'] = $media['verified_at'] = gmdate('Y-m-d\TH:i:s\Z');
    foreach (['private', 'web', 'web/uploads', 'download'] as $dir) { mkdir($work . '/' . $dir, 0700); }
    $workspace = new RecoveryWorkspace($work . '/private', $set, $work . '/web', $work . '/web/uploads');
    $store = new S3FixtureStore($work . '/private/collector.json');
    $collector = new RecoveryCollector($store);
    requireFixture($collector->enqueue($set, $old['environment']));
    $step = new S3FixtureEvidence($db, $media);
    for ($i = 0; $i < 3; ++$i) { $collector->tick($set->id, $step); }
    requireFixture($collector->current()['phase'] === 'ready');
    $phase = 'manifest_publication';
    $loss = new LostManifestAcknowledgement($client);
    requireFixture($collector->publish($set->id, new RecoveryPublisher($loss, $workspace)) === 'publishing');
    requireFixture((new RecoveryCollector($store))->publish($set->id, new RecoveryPublisher($loss, $workspace)) === 'complete' && $loss->puts === 1);
    $bytes = $collector->current()['manifest']; $key = $set->root() . 'manifest.json';
    // Actual 412 conflict; existing manifest must remain unchanged.
    $phase = 'conditional_conflict';
    $conflict = $client->request('PutObject', ['Bucket' => $bucket, 'Key' => $key, 'Body' => 'conflict',
        'ContentLength' => 8, 'ChecksumSHA256' => base64_encode(hash('sha256', 'conflict', true)), 'IfNoneMatch' => '*'], time() + 60);
    requireFixture(($conflict['exists'] ?? false) === true);
    $receipt = $collector->current()['checkpoint'];
    $manifestHead = $client->request('HeadObject', ['Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED'], time() + 60);
    $report['objects'][] = ['key' => $key, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        'version_id' => $manifestHead['VersionId'] ?? null, 'encryption' => $manifestHead['ServerSideEncryption'] ?? 'not-reported'];
    $phase = 'independent_download';
    foreach ($report['objects'] as $a) {
        $args = ['Bucket' => $bucket, 'Key' => $a['key']];
        if ($a['version_id'] !== null) { $args['VersionId'] = $a['version_id']; }
        $response = $client->request('GetObject', $args, time() + 60);
        $body = $response['Body'];
        $destination = $work . '/download/' . hash('sha256', $bucket . '/' . $a['key']);
        $out = fopen($destination, 'xb'); $hash = hash_init('sha256'); $size = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536); $size += strlen($chunk);
                requireFixture($size <= $a['size_bytes'] && ($chunk !== '' || $body->eof()));
                requireFixture(fwrite($out, $chunk) === strlen($chunk)); hash_update($hash, $chunk);
            }
        } finally { $body->close(); fclose($out); }
        requireFixture($size === $a['size_bytes'] && hash_final($hash) === $a['sha256']);
    }
    $report += ['receipt' => $receipt, 'lost_ack_reconciled_without_second_put' => true, 'conditional_conflict_412' => true,
        'all_downloads_verified' => true, 'checked_at' => gmdate(DATE_ATOM)];
    file_put_contents($work . '/download/locator.json', json_encode(['bucket' => $bucket, 'key' => $key, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR));
    file_put_contents($work . '/download/s3-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($work . '/download/collector.json', $store->read());
    $process = proc_open(['tar', '-C', $work . '/download', '-cf', dirname($work) . '/output.tar', '.'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    requireFixture(is_resource($process) && proc_close($process) === 0);
    echo 'PASS real S3 recovery transport backend=' . $argv[3] . ' objects=' . count($report['objects']) . ' prefix=' . $prefix . "\n";
} catch (Throwable $e) {
    $cause = $e; while ($cause->getPrevious() !== null) { $cause = $cause->getPrevious(); }
    $code = $cause instanceof \Aws\Exception\AwsException ? $cause->getAwsErrorCode() : get_class($cause);
    fwrite(STDERR, 'Real S3 recovery failed stage=' . $phase . ' code=' . preg_replace('/[^A-Za-z0-9_\\\\]/', '', (string) $code) . "\n");
    exit(1);
}
