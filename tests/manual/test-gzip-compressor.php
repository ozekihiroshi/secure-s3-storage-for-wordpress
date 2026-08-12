<?php

use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;

require __DIR__ . '/../../vendor/autoload.php';

$dbHost = getenv('WORDPRESS_DB_HOST') ?: '';
$dbName = getenv('WORDPRESS_DB_NAME') ?: '';
$dbUser = getenv('WORDPRESS_DB_USER') ?: '';
$dbPassword = getenv('WORDPRESS_DB_PASSWORD') ?: '';

if (
    $dbHost === ''
    || $dbName === ''
    || $dbUser === ''
    || $dbPassword === ''
) {
    fwrite(
        STDERR,
        "Database environment variables are missing.\n"
    );

    exit(1);
}

$host = $dbHost;
$port = 3306;

if (str_contains($dbHost, ':')) {
    [$host, $portString] = explode(':', $dbHost, 2);

    if (ctype_digit($portString)) {
        $port = (int) $portString;
    }
}

$connection = new DatabaseConnection(
    host: $host,
    port: $port,
    databaseName: $dbName,
    username: $dbUser,
    password: $dbPassword
);

$backupService = new BackupService();
$compressor = new GzipCompressor();

$dumpPath = null;
$gzipPath = null;

try {
    $dumpResult = $backupService->backupDatabase(
        $connection
    );

    $dumpPath = $dumpResult->getPath();

    echo "Dump size: "
        . $dumpResult->getSizeBytes()
        . " bytes\n";

    $compressed = $compressor->compress(
        $dumpPath
    );

    $gzipPath = $compressed->getPath();

    echo "Compression successful.\n";
    echo "Path: " . $compressed->getPath() . "\n";
    echo "Size: " . $compressed->getSizeBytes() . " bytes\n";
    echo "Algorithm: " . $compressed->getAlgorithm() . "\n";

    if (! is_file($gzipPath)) {
        throw new RuntimeException(
            'Compressed result file does not exist.'
        );
    }

    echo "Compressed file exists: yes\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'Compression test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);

} finally {
    if ($gzipPath !== null && is_file($gzipPath)) {
        unlink($gzipPath);
    }

    if ($dumpPath !== null && is_file($dumpPath)) {
        unlink($dumpPath);
    }
}