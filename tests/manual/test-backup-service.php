<?php

use SecureS3StorageForWordpress\Backup\BackupService;
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

$service = new BackupService();

try {
    echo 'Selected backend: '
        . $service->getSelectedBackendName()
        . PHP_EOL;

    echo 'Detected utility: '
        . ($service->getDetectedUtility() ?? 'none')
        . PHP_EOL;

    $result = $service->backupDatabase($connection);

    echo "Backup successful.\n";
    echo "Path: " . $result->getPath() . PHP_EOL;
    echo "Size: " . $result->getSizeBytes() . " bytes\n";
    echo "Database: " . $result->getDatabaseName() . PHP_EOL;
    echo "Engine: " . $result->getEngine() . PHP_EOL;

    if (! is_file($result->getPath())) {
        throw new RuntimeException(
            'BackupService result file does not exist.'
        );
    }

    echo "File exists: yes\n";

    unlink($result->getPath());

    echo "Test backup removed.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'BackupService test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}