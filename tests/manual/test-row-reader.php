<?php

use SecureS3StorageForWordpress\Backup\Database\Php\RowReader;

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

$reader = new RowReader();

$limit = 2;
$offset = 0;
$chunkNumber = 1;
$totalRows = 0;

try {
    while (true) {
        $rows = $reader->readChunk(
            $mysqli,
            $dbName,
            'wp_posts',
            $limit,
            $offset
        );

        $count = count($rows);

        echo sprintf(
            "Chunk %d: %d rows\n",
            $chunkNumber,
            $count
        );

        if ($count === 0) {
            break;
        }

        foreach ($rows as $row) {
            echo sprintf(
                "  ID=%s post_type=%s post_status=%s\n",
                $row['ID'] ?? '',
                $row['post_type'] ?? '',
                $row['post_status'] ?? ''
            );
        }

        $totalRows += $count;
        $offset += $count;
        $chunkNumber++;
    }

    echo "Total rows read: {$totalRows}\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'RowReader test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
} finally {
    $mysqli->close();
}