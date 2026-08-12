<?php

use SecureS3StorageForWordpress\Backup\Database\Php\SchemaReader;

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

$reader = new SchemaReader();

try {
    $tables = $reader->getTableNames(
        $mysqli,
        $dbName
    );

    echo "Tables found: " . count($tables) . PHP_EOL;

    foreach ($tables as $table) {
        echo "- {$table}" . PHP_EOL;
    }

    if (! in_array('wp_posts', $tables, true)) {
        throw new RuntimeException(
            'wp_posts table was not found.'
        );
    }

    $createSql = $reader->getCreateTableSql(
        $mysqli,
        $dbName,
        'wp_posts'
    );

    echo PHP_EOL;
    echo "wp_posts CREATE TABLE:" . PHP_EOL;
    echo $createSql . PHP_EOL;
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'SchemaReader test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
} finally {
    $mysqli->close();
}