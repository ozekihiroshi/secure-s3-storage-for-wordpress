<?php

// EC2 half: synthetic SQL only, fixed test bucket, unique prefix. No DB access or WordPress.
require __DIR__ . '/../vendor/autoload.php';

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Compression\GzipVerifier;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

if (PHP_SAPI !== 'cli' || $argc !== 2) { throw new RuntimeException('Private fixture directory required.'); }
$work = realpath($argv[1]);
if ($work === false || ! preg_match('#^/tmp/odbfs3-db-s3\.[A-Za-z0-9]+/fixture$#D', $work)) { throw new RuntimeException('Only isolated fixture paths are allowed.'); }
$info = json_decode(file_get_contents($work . '/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
if (($info['format'] ?? '') !== 'odbfs3-db-synthetic-fixture' || ($info['version'] ?? null) !== 1
    || ! preg_match('/^[a-f0-9]{24}$/D', $info['run'] ?? '')) { throw new RuntimeException('Invalid synthetic fixture.'); }
$bucket = 'ceri-secure-s3-storage-test';
$prefix = 'wordpress-test/database-integrity-test/' . $info['run'] . '/';
$client = (new S3ClientFactory())->create('ap-northeast-1');
$storage = new S3Storage($client);
$report = ['run' => $info['run'], 'bucket' => $bucket, 'prefix' => $prefix, 'backends' => []];
try {
    $client->headBucket(['Bucket' => $bucket]);
    foreach (['native', 'php'] as $backend) {
        $entry = $info['backends'][$backend];
        $source = $work . '/' . $backend . '.sql';
        if ($entry['sql_size'] > 4 * 1024 * 1024 || filesize($source) !== $entry['sql_size']
            || ! hash_equals($entry['sql_sha256'], hash_file('sha256', $source))) { throw new RuntimeException('Synthetic SQL does not match local evidence.'); }
        $gzip = (new GzipCompressor())->compress($source);
        $raw = (new GzipVerifier())->verify($gzip->getPath(), $entry['sql_size']);
        if ($raw['sha256'] !== $entry['sql_sha256'] || $raw['sizeBytes'] !== $entry['sql_size']) { throw new RuntimeException('Compression mismatch.'); }
        $key = $prefix . 'backups/database/' . gmdate('Y/m/d') . '/db-' . $backend . '-' . bin2hex(random_bytes(16)) . '.sql.gz';
        $uploaded = $storage->upload($gzip->getPath(), $bucket, $key);
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED']);
        $storage->downloadVerified($bucket, $key, $work . '/' . $backend . '-download.gz', $uploaded->getSizeBytes(), $uploaded->getSha256());
        $restored = (new GzipVerifier())->verify($work . '/' . $backend . '-download.gz', $entry['sql_size'], $work . '/' . $backend . '-download.sql');
        if ($restored !== $raw) { throw new RuntimeException('Downloaded SQL differs.'); }
        // Verify that an existing key cannot be replaced, using the exact same synthetic bytes.
        $blocked = false;
        try { $storage->upload($gzip->getPath(), $bucket, $key); }
        catch (RuntimeException $e) {
            $cause = $e->getPrevious();
            $blocked = $cause instanceof \Aws\Exception\AwsException && $cause->getStatusCode() === 412;
        }
        if (! $blocked) { throw new RuntimeException('Expected conditional-write conflict was not observed.'); }
        $report['backends'][$backend] = ['key' => $key, 'gzip_bytes' => $uploaded->getSizeBytes(), 'gzip_sha256' => $uploaded->getSha256(),
            'sql_bytes' => $restored['sizeBytes'], 'sql_sha256' => $restored['sha256'], 'encryption' => $head['ServerSideEncryption'] ?? 'not-reported',
            'put_head_get_verified' => true, 'overwrite_rejected_412' => true];
        echo 'PASS S3 upload/head/download/gzip backend=' . $backend . "\n";
    }
    $report['checked_at'] = gmdate(DATE_ATOM);
    $out = SecureTemporaryFile::openForWriting($work . '/s3-result.json');
    try {
        \SecureS3StorageForWordpress\Backup\CompleteStreamWriter::writeAll(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", static fn ($s) => fwrite($out, $s), 'Unable to save evidence.');
    } finally { fclose($out); }
    echo 'Evidence saved; test objects retained at s3://' . $bucket . '/' . $prefix . "\n";
} catch (Throwable $e) {
    // No raw SDK exceptions, signed requests, credentials, or SQL are printed.
    $cause = $e;
    while ($cause->getPrevious() !== null) { $cause = $cause->getPrevious(); }
    $code = $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : 'verification_failed';
    fwrite(STDERR, 'Real S3 test failed: ' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $code) . "\n");
    exit(1);
}
