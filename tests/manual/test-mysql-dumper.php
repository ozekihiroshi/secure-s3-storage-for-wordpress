<?php
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\NativeMySqlDumper;

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
    fwrite(STDERR, "Database environment variables are missing.\n");
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
    password: $dbPassword,
);

$dumper = new NativeMySqlDumper();

try {
    $result = $dumper->dump($connection);

    echo "Dump successful.\n";
    echo "Path: " . $result->getPath() . "\n";
    echo "Size: " . $result->getSizeBytes() . " bytes\n";
    echo "Database: " . $result->getDatabaseName() . "\n";
    echo "Engine: " . $result->getEngine() . "\n";

    if (! is_file($result->getPath())) {
        throw new RuntimeException('Dump result file does not exist.');
    }

    echo "File exists: yes\n";

    // Manual test only: remove the dump after verification.
    unlink($result->getPath());

    echo "Test dump removed.\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'Dump failed: ' . $e->getMessage() . PHP_EOL
    );

    exit(1);
}