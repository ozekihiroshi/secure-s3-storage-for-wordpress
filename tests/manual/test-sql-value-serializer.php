<?php

use SecureS3StorageForWordpress\Backup\Database\Php\SqlValueSerializer;

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

$serializer = new SqlValueSerializer();

$tests = [
    'null_text' => [
        'value' => null,
        'column' => [
            'name' => 'test',
            'dataType' => 'text',
            'columnType' => 'text',
            'characterSet' => 'utf8mb4',
            'nullable' => true,
        ],
    ],

    'integer' => [
        'value' => '123',
        'column' => [
            'name' => 'test',
            'dataType' => 'int',
            'columnType' => 'int(11)',
            'characterSet' => null,
            'nullable' => false,
        ],
    ],

    'decimal' => [
        'value' => '12.34',
        'column' => [
            'name' => 'test',
            'dataType' => 'decimal',
            'columnType' => 'decimal(10,2)',
            'characterSet' => null,
            'nullable' => false,
        ],
    ],

    'simple' => [
        'value' => 'simple text',
        'column' => [
            'name' => 'test',
            'dataType' => 'varchar',
            'columnType' => 'varchar(255)',
            'characterSet' => 'utf8mb4',
            'nullable' => false,
        ],
    ],

    'quote' => [
        'value' => "O'Reilly",
        'column' => [
            'name' => 'test',
            'dataType' => 'varchar',
            'columnType' => 'varchar(255)',
            'characterSet' => 'utf8mb4',
            'nullable' => false,
        ],
    ],

    'newline' => [
        'value' => "line1\nline2",
        'column' => [
            'name' => 'test',
            'dataType' => 'text',
            'columnType' => 'text',
            'characterSet' => 'utf8mb4',
            'nullable' => false,
        ],
    ],

    'backslash' => [
        'value' => 'C:\\path\\file',
        'column' => [
            'name' => 'test',
            'dataType' => 'varchar',
            'columnType' => 'varchar(255)',
            'characterSet' => 'utf8mb4',
            'nullable' => false,
        ],
    ],

    'japanese' => [
        'value' => '日本語のテスト',
        'column' => [
            'name' => 'test',
            'dataType' => 'text',
            'columnType' => 'text',
            'characterSet' => 'utf8mb4',
            'nullable' => false,
        ],
    ],

    'binary' => [
        'value' => "\x00\x01\x02\xFF",
        'column' => [
            'name' => 'binary_data',
            'dataType' => 'blob',
            'columnType' => 'blob',
            'characterSet' => null,
            'nullable' => false,
        ],
    ],
];

try {
    foreach ($tests as $name => $test) {
        $serialized = $serializer->serialize(
               $mysqli,
            $test['value'],
            $test['column']
        );
        echo sprintf(
            "%-10s => %s\n",
            $name,
            $serialized
        );
    }
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'SqlValueSerializer test failed: '
            . $e->getMessage()
            . PHP_EOL
    );

    exit(1);
} finally {
    $mysqli->close();
}
