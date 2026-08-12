<?php

use SecureS3StorageForWordpress\Backup\Database\Php\ColumnMetadataReader;
use SecureS3StorageForWordpress\Backup\Database\Php\RowReader;
use SecureS3StorageForWordpress\Backup\Database\Php\SchemaReader;
use SecureS3StorageForWordpress\Backup\Database\Php\SqlValueSerializer;
use SecureS3StorageForWordpress\Backup\Database\Php\SqlWriter;

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

$schemaReader = new SchemaReader();
$columnReader = new ColumnMetadataReader();
$rowReader = new RowReader();
$serializer = new SqlValueSerializer();

$outputPath = '/tmp/secure-s3-sql-writer-test.sql';

try {
    $createTableSql = $schemaReader->getCreateTableSql(
        $mysqli,
        $dbName,
        'wp_posts'
    );

    $columns = $columnReader->getColumns(
        $mysqli,
        $dbName,
        'wp_posts'
    );

    $rows = $rowReader->readChunk(
        $mysqli,
        $dbName,
        'wp_posts',
        2,
        0
    );

    $writer = new SqlWriter($outputPath);

    try {
        $writer->writeHeader();

        $writer->writeCreateTable(
            'wp_posts',
            $createTableSql
        );

        $writer->writeInsertRows(
            'wp_posts',
            $rows,
            $columns,
            $serializer,
            $mysqli
        );

        $writer->writeFooter();
    } finally {
        $writer->close();
    }

    if (! is_file($outputPath)) {
        throw new RuntimeException(
            'SQL writer output file was not created.'
        );
    }

    $size = filesize($outputPath);

    if ($size === false || $size <= 0) {
        throw new RuntimeException(
            'SQL writer output file is empty.'
        );
    }

    echo "SQL writer test successful.\n";
    echo "Path: {$outputPath}\n";
    echo "Size: {$size} bytes\n";
    echo "Rows written: " . count($rows) . "\n";

    echo "\n--- SQL preview ---\n";

    $contents = file_get_contents($outputPath);

    if ($contents === false) {
        throw new RuntimeException(
            'Unable to read generated SQL file.'
        );
    }

    echo $contents;

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'SqlWriter test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
} finally {
    $mysqli->close();
}