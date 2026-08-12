<?php

use SecureS3StorageForWordpress\Backup\Database\Php\ColumnMetadataReader;

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

$mysqli = new mysqli(
    $host,
    $dbUser,
    $dbPassword,
    $dbName,
    $port
);

if ($mysqli->connect_errno) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$mysqli->set_charset('utf8mb4');

$reader = new ColumnMetadataReader();

try {
    $columns = $reader->getColumns(
        $mysqli,
        $dbName,
        'wp_posts'
    );

    echo 'Columns found: ' . count($columns) . PHP_EOL;

    foreach ($columns as $column) {
        echo sprintf(
            "%-24s type=%-12s columnType=%-20s charset=%s nullable=%s\n",
            $column['name'],
            $column['dataType'],
            $column['columnType'],
            $column['characterSet'] ?? '-',
            $column['nullable'] ? 'yes' : 'no'
        );
    }
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'ColumnMetadataReader test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
} finally {
    $mysqli->close();
}