<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/database-s3-test-transport.php';

use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Compression\GzipVerifier;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryEntry;

$directory = sys_get_temp_dir() . '/odbfs3-db-integrity-' . bin2hex(random_bytes(12));
if (! mkdir($directory, 0700)) { throw new RuntimeException('Unable to create fixture'); }
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (RuntimeException $e) { $check(true, $message); return; }
    throw new RuntimeException('Accepted invalid case: ' . $message);
};
try {
    $sql = str_repeat("INSERT INTO t VALUES ('日本語😀',NULL,0x00ff);\n", 20000);
    $source = $directory . '/dump.sql';
    file_put_contents($source, $sql);
    $compressed = (new GzipCompressor())->compress($source);
    $gzip = $compressed->getPath();
    $verifier = new GzipVerifier();
    $result = $verifier->verify($gzip, strlen($sql));
    $check($result === ['sizeBytes' => strlen($sql), 'sha256' => hash('sha256', $sql)], 'gzip matches input');
    $reject(fn () => $verifier->verify($gzip, 100, $directory . '/oversize'), 'expansion limit');
    $check(! file_exists($directory . '/oversize'), 'failed expansion removed');
    $encoded = file_get_contents($gzip);
    $crc = $encoded;
    $crc[strlen($crc) - 8] = chr(ord($crc[strlen($crc) - 8]) ^ 1);
    foreach (['truncated' => substr($encoded, 0, -1), 'crc' => $crc, 'trailing' => $encoded . 'x',
        'members' => $encoded . $encoded, 'plain' => $sql, 'empty' => gzencode('')] as $name => $bytes) {
        $path = $directory . '/' . $name;
        file_put_contents($path, $bytes);
        $reject(fn () => $verifier->verify($path, strlen($sql) * 2, $directory . '/invalid-output'), $name);
        $check(! file_exists($directory . '/invalid-output'), 'invalid output removed');
    }
    $transport = new DatabaseS3TestTransport();
    $storage = new S3Storage($transport->client());
    $uploaded = $storage->upload($gzip, 'fixture-bucket', 'backups/database/valid.sql.gz');
    $check($uploaded->getSha256() === hash_file('sha256', $gzip), 'persistable compressed hash');
    $check($transport->calls === ['PUT', 'HEAD'], 'upload followed by independent HEAD');
    $reject(fn () => $storage->upload($gzip, 'fixture-bucket', 'backups/database/valid.sql.gz'), 'no overwrite');
    foreach (['size', 'hash', 'missing-hash', 'denied'] as $fault) {
        $transport->fault = $fault;
        $reject(fn () => $storage->upload($gzip, 'fixture-bucket', 'backups/database/' . $fault . '.sql.gz'), 'HEAD ' . $fault);
    }
    $transport->fault = '';
    $download = $directory . '/download.gz';
    $storage->downloadVerified('fixture-bucket', $uploaded->getKey(), $download, $uploaded->getSizeBytes(), $uploaded->getSha256());
    $expanded = $verifier->verify($download, strlen($sql), $directory . '/restored.sql');
    $check($expanded === $result && file_get_contents($directory . '/restored.sql') === $sql, 'independent download and expansion');
    $reject(fn () => $storage->downloadVerified('fixture-bucket', $uploaded->getKey(), $download, $uploaded->getSizeBytes(), $uploaded->getSha256()), 'existing download preserved');
    $check(hash_file('sha256', $download) === $uploaded->getSha256(), 'existing content unchanged');
    $reject(fn () => $verifier->verify($gzip, strlen($sql), $source), 'existing expansion preserved');
    $check(file_get_contents($source) === $sql, 'existing SQL unchanged');
    $reject(fn () => (new GzipCompressor())->compress($source), 'existing gzip preserved');
    $check(file_get_contents($gzip) === $encoded, 'compressor does not delete existing artifact');
    foreach (['body', 'truncated', 'size', 'denied', 'oversize'] as $fault) {
        $transport->fault = $fault;
        $reject(fn () => $storage->downloadVerified('fixture-bucket', $uploaded->getKey(), $directory . '/bad-download', $uploaded->getSizeBytes(), $uploaded->getSha256()), 'GET ' . $fault);
        $check(! file_exists($directory . '/bad-download'), 'failed download removed');
    }
    $history = new BackupHistoryEntry(true, new DateTimeImmutable(), 'fixture', 'php', sha256: $uploaded->getSha256(), verification: 'remote_integrity');
    $check($history->toArray()['sha256'] === $uploaded->getSha256() && $history->toArray()['verification'] === 'remote_integrity', 'history evidence');
    $legacy = new BackupHistoryEntry(true, new DateTimeImmutable(), 'fixture', 'php');
    $check($legacy->toArray()['verification'] === 'unverified', 'legacy not promoted to verified');
    $service = new \SecureS3StorageForWordpress\Backup\DatabaseBackupService(new \SecureS3StorageForWordpress\Backup\BackupService(), new GzipCompressor(), $storage);
    $keyMethod = new ReflectionMethod($service, 'buildBackupKey');
    $keyOne = $keyMethod->invoke($service, 'site', 'fixture');
    $keyTwo = $keyMethod->invoke($service, 'site', 'fixture');
    $check($keyOne !== $keyTwo && preg_match('/-[a-f0-9]{32}\.sql\.gz$/D', $keyOne) === 1, 'unique backup identifiers');
    $retention = new \SecureS3StorageForWordpress\Backup\Retention\S3BackupRetentionManager($transport->client());
    $keyMatch = new ReflectionMethod($retention, 'isDatabaseBackupKey');
    $check($keyMatch->invoke($retention, $keyOne, 'site/backups/database/') === true, 'new key recognized by retention');
    $check($keyMatch->invoke($retention, 'site/backups/database/2026/10/17/db-fixture-20261017-010203.sql.gz', 'site/backups/database/') === true, 'legacy key recognized by retention');
    echo "PASS database integrity checks=$checks\n";
} finally {
    // Remove only direct files in the newly created test-owned directory.
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isFile() && ! $file->isLink()) { unlink($file->getPathname()); }
    }
    rmdir($directory);
}
