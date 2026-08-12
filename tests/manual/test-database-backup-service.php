<?php

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\DatabaseBackupService;

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

$region = 'ap-northeast-1';
$bucket = 'ceri-secure-s3-storage-test';
$prefix = 'wordpress-test';

$clientFactory = new S3ClientFactory();
$client = $clientFactory->create($region);

$backupService = new BackupService();
$compressor = new GzipCompressor();
$storage = new S3Storage($client);

$databaseBackupService = new DatabaseBackupService(
    $backupService,
    $compressor,
    $storage
);

try {
    echo "Starting database backup test...\n";

    echo 'Selected backend: '
        . $backupService->getSelectedBackendName()
        . PHP_EOL;

    echo 'Detected utility: '
        . ($backupService->getDetectedUtility() ?? 'none')
        . PHP_EOL;

    $result = $databaseBackupService->backup(
        $connection,
        $bucket,
        $prefix
    );

    echo "Database backup successful.\n";
    echo "Bucket: " . $result->getBucket() . PHP_EOL;
    echo "Key: " . $result->getKey() . PHP_EOL;
    echo "Size: " . $result->getSizeBytes() . " bytes\n";
    echo "Database: " . $result->getDatabaseName() . PHP_EOL;
    echo "Backend: " . $result->getBackend() . PHP_EOL;
    echo "Created: "
        . $result->getCreatedAt()->format(DATE_ATOM)
        . PHP_EOL;
    echo "ETag: "
        . ($result->getEtag() ?? 'none')
        . PHP_EOL;

    $client->headObject([
        'Bucket' => $result->getBucket(),
        'Key' => $result->getKey(),
    ]);

    echo "S3 object exists: yes\n";

    echo "Backup retained in S3 for restore verification.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'DatabaseBackupService test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    if ($e->getPrevious() !== null) {
        fwrite(
            STDERR,
            'Cause: '
            . get_class($e->getPrevious())
            . ' - '
            . $e->getPrevious()->getMessage()
            . PHP_EOL
        );
    }

    exit(1);
}