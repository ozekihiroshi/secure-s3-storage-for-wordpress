<?php
declare(strict_types=1);

// Run only against an extracted fixed ZIP and synthetic, explicitly tracked keys.
if (PHP_SAPI !== 'cli' || $argc !== 3) { throw new RuntimeException('Runtime and ZIP hash required'); }
$runtime = realpath($argv[1]);
if ($runtime === false || ! preg_match('~^/tmp/odbfs3-retention-[A-Za-z0-9]+/ozeki-database-backup-for-s3$~D', $runtime)
    || ! preg_match('/^[a-f0-9]{64}$/D', $argv[2])) { throw new RuntimeException('Invalid fixture location'); }
$root = dirname($runtime);
if (hash_file('sha256', $root . '/candidate.zip') !== $argv[2]) { throw new RuntimeException('ZIP hash mismatch'); }
require $runtime . '/vendor/autoload.php';

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\Retention\RetentionPolicy;
use SecureS3StorageForWordpress\Backup\Retention\S3BackupRetentionManager;

$bucket = 'ceri-secure-s3-storage-test';
$prefix = 'wordpress-test/retention-041/' . bin2hex(random_bytes(12));
$client = (new S3ClientFactory())->create('ap-northeast-1');
$storage = new S3Storage($client);
$manager = new S3BackupRetentionManager($client);
$body = gzencode("-- synthetic retention fixture only\nSELECT 1;\n");
$file = $root . '/fixture.sql.gz';
file_put_contents($file, $body); chmod($file, 0600);
$hash = hash('sha256', $body); $checks = 0;
$report = ['zip_sha256' => $argv[2], 'bucket' => $bucket, 'prefix' => $prefix, 'cases' => [], 'created_keys' => [], 'deleted_keys' => []];
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) { throw new RuntimeException($message); }
    ++$checks;
};
$verify = static function (string $key) use ($client, $bucket, $body, $check): void {
    $response = $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED']);
    $check((string) $response['Body'] === $body, 'Independent GET differs');
};
try {
    foreach (['normal', 'missing_metadata', 'mismatched_metadata'] as $case) {
        $casePrefix = $prefix . '/' . $case;
        $keys = [];
        foreach (['old', 'middle', 'new'] as $which) {
            $key = $casePrefix . '/backups/database/' . gmdate('Y/m/d') . '/db-' . $which . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(16)) . '.sql.gz';
            $keys[] = $key; $report['created_keys'][] = $key;
            if ($which === 'old' || ($which === 'middle' && $case !== 'normal')) {
                $args = ['Bucket' => $bucket, 'Key' => $key, 'Body' => $body, 'ContentType' => 'application/gzip',
                    'ChecksumSHA256' => base64_encode(hex2bin($hash)), 'IfNoneMatch' => '*'];
                if ($case === 'mismatched_metadata' && $which === 'middle') { $args['Metadata'] = ['odbfs3-sha256' => str_repeat('0', 64)]; }
                $client->putObject($args);
            } else {
                $result = $storage->upload($file, $bucket, $key);
                $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED']);
                $check(($head['Metadata']['odbfs3-sha256'] ?? null) === $hash && $result->getSha256() === $hash, 'Packaged upload metadata differs');
            }
            // Make LastModified ordering unambiguous even with S3's second precision.
            sleep(2);
        }
        $unrelated = $casePrefix . '/backups/database/manual/db-precious.sql.gz';
        $client->putObject(['Bucket' => $bucket, 'Key' => $unrelated, 'Body' => $body, 'IfNoneMatch' => '*', 'ChecksumSHA256' => base64_encode(hex2bin($hash))]);
        $report['created_keys'][] = $unrelated;
        $blocked = false;
        try { $candidates = $manager->findDeletionCandidates($bucket, $casePrefix, new RetentionPolicy(2)); }
        catch (RuntimeException $e) { $blocked = true; $candidates = []; }
        if ($case === 'normal') {
            $check(! $blocked && count($candidates) === 1 && $candidates[0]->getKey() === $keys[0], 'Unexpected deletion plan');
            foreach ($candidates as $candidate) {
                $check($candidate->getBucket() === $bucket && in_array($candidate->getKey(), $keys, true)
                    && str_starts_with($candidate->getKey(), $prefix . '/'), 'Deletion escaped fixture');
            }
            $deleted = $manager->deleteCandidates($candidates);
            $check($deleted->getDeletedCount() === 1, 'Old generation not deleted');
            $report['deleted_keys'][] = $keys[0];
            $remaining = $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $casePrefix . '/']);
            $names = array_column($remaining['Contents'] ?? [], 'Key');
            $check(! ($remaining['IsTruncated'] ?? true) && count($names) === 3 && ! in_array($keys[0], $names, true), 'Unexpected remaining objects');
            $verify($keys[1]); $verify($keys[2]);
        } else {
            $check($blocked && $candidates === [], 'Unverified retained object allowed deletion');
            foreach ($keys as $key) { $verify($key); }
        }
        $verify($unrelated);
        $report['cases'][$case] = ['passed' => true, 'retention_blocked' => $blocked, 'unrelated_object_preserved' => true];
    }
    // Existing objects must still reject overwrite in the distributed runtime.
    $conflict = false;
    try { $storage->upload($file, $bucket, $keys[2]); }
    catch (RuntimeException $e) {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (method_exists($cause, 'getStatusCode') && $cause->getStatusCode() === 412) { $conflict = true; }
        }
    }
    $check($conflict, 'Conditional overwrite was not rejected');
    $verify($keys[2]);
    $report += ['passed' => true, 'checks' => $checks, 'checked_at' => gmdate(DATE_ATOM),
        'retained_fixture_objects' => count($report['created_keys']) - count($report['deleted_keys']),
        'scope' => 'Fixed ZIP runtime, real S3 PUT/HEAD/GET/list/delete, synthetic gzip, no production DB'];
    file_put_contents($root . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    // Do not print SDK requests, credentials or exception chains.
    $awsCode = null;
    for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
        if (method_exists($cause, 'getAwsErrorCode')) { $awsCode = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cause->getAwsErrorCode()); }
    }
    $report += ['passed' => false, 'checks' => $checks, 'failure_class' => get_class($e), 'aws_error_code' => $awsCode];
    file_put_contents($root . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDERR, "Retention release fixture failed; inspect private evidence.\n"); exit(1);
}
