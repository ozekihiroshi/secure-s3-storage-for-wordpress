<?php

use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;

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

/*
 * Use a deliberately small chunk size so the manual test
 * exercises chunk iteration even on this small WordPress DB.
 */
$dumper = new PhpMySqlDumper(
    chunkSize: 2
);

try {
    $result = $dumper->dump($connection);

    echo "PHP MySQL dump successful.\n";
    echo "Path: " . $result->getPath() . "\n";
    echo "Size: " . $result->getSizeBytes() . " bytes\n";
    echo "Database: " . $result->getDatabaseName() . "\n";
    echo "Engine: " . $result->getEngine() . "\n";
    echo "Created: "
        . $result->getCreatedAt()->format(DATE_ATOM)
        . "\n";

    if (! is_file($result->getPath())) {
        throw new RuntimeException(
            'PHP dump result file does not exist.'
        );
    }

    echo "File exists: yes\n";
    echo "Dump retained for restore test.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'PhpMySqlDumper test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    /*
     * For debugging only, expose the class of the underlying error
     * but not credentials or the complete exception dump.
     */
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