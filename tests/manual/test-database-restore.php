<?php

use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\NativeMySqlDumper;
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

require __DIR__ . '/../../vendor/autoload.php';

$dbHost = getenv('WORDPRESS_DB_HOST') ?: '';
$sourceDatabase = getenv('WORDPRESS_DB_NAME') ?: '';
$dbUser = getenv('WORDPRESS_DB_USER') ?: '';
$dbPassword = getenv('WORDPRESS_DB_PASSWORD') ?: '';
$restoreDatabase = getenv('RESTORE_TEST_DB_NAME') ?: '';
$backend = getenv('RESTORE_TEST_BACKEND') ?: '';
$useFixture = getenv('RESTORE_TEST_FIXTURE') === '1';

if (
    $dbHost === ''
    || $sourceDatabase === ''
    || $dbUser === ''
    || $dbPassword === ''
) {
    fail('Database environment variables are missing.');
}

if (
    preg_match('/^secure_s3_restore_test_[a-f0-9]{12}$/', $restoreDatabase)
    !== 1
    || $restoreDatabase === $sourceDatabase
) {
    fail('The restore database name is not an approved temporary name.');
}

if (! in_array($backend, ['native', 'php'], true)) {
    fail('RESTORE_TEST_BACKEND must be native or php.');
}

if (
    $useFixture
    && preg_match(
        '/^secure_s3_restore_source_[a-f0-9]{12}$/',
        $sourceDatabase
    ) !== 1
) {
    fail('Fixture data may only be created in an approved temporary database.');
}

[$host, $port] = parseHost($dbHost);
$source = new DatabaseConnection(
    host: $host,
    port: $port,
    databaseName: $sourceDatabase,
    username: $dbUser,
    password: $dbPassword,
);

$dumpPath = null;
$optionPath = null;

try {
    if ($useFixture) {
        $fixtureMysqli = connectDatabase(
            $host,
            $port,
            $dbUser,
            $dbPassword,
            $sourceDatabase
        );
        createFixture($fixtureMysqli);
        $fixtureMysqli->close();
    }

    $dumper = $backend === 'native'
        ? new NativeMySqlDumper()
        : new PhpMySqlDumper(chunkSize: 2);

    $result = $dumper->dump($source);
    $dumpPath = $result->getPath();

    importDump(
        $host,
        $port,
        $dbUser,
        $dbPassword,
        $restoreDatabase,
        $dumpPath,
        $optionPath
    );

    $sourceMysqli = connectDatabase(
        $host,
        $port,
        $dbUser,
        $dbPassword,
        $sourceDatabase
    );
    $restoreMysqli = connectDatabase(
        $host,
        $port,
        $dbUser,
        $dbPassword,
        $restoreDatabase
    );

    compareDatabases($sourceMysqli, $restoreMysqli);

    $sourceMysqli->close();
    $restoreMysqli->close();

    echo sprintf(
        "Restore verification (%s): OK\n",
        $backend
    );
    echo 'Dump size: ' . $result->getSizeBytes() . " bytes\n";
} catch (Throwable $e) {
    fail(
        'Restore verification failed: ' . $e->getMessage(),
        $dumpPath,
        $optionPath
    );
} finally {
    removeFile($optionPath);
    removeFile($dumpPath);
}

/**
 * @return array{0: string, 1: int}
 */
function parseHost(string $dbHost): array
{
    $host = $dbHost;
    $port = 3306;

    if (str_contains($dbHost, ':')) {
        [$host, $portString] = explode(':', $dbHost, 2);

        if (ctype_digit($portString)) {
            $port = (int) $portString;
        }
    }

    return [$host, $port];
}

function importDump(
    string $host,
    int $port,
    string $username,
    string $password,
    string $database,
    string $dumpPath,
    ?string &$optionPath
): void {
    $optionPath = sys_get_temp_dir()
        . '/secure-s3-restore-client-'
        . bin2hex(random_bytes(8))
        . '.cnf';

    $handle = SecureTemporaryFile::openForWriting($optionPath);

    try {
        $content = implode("\n", [
            '[client]',
            'host=' . $host,
            'port=' . $port,
            'user=' . $username,
            'password=' . quoteOptionValue($password),
            'default-character-set=utf8mb4',
            '',
        ]);

        CompleteStreamWriter::writeAll(
            $content,
            static fn (string $chunk): int|false => fwrite($handle, $chunk),
            'Unable to write restore client option file.'
        );
    } finally {
        fclose($handle);
    }

    $input = fopen($dumpPath, 'rb');

    if ($input === false) {
        throw new RuntimeException('Unable to open the dump for import.');
    }

    $process = proc_open(
        [
            'mariadb',
            '--defaults-extra-file=' . $optionPath,
            $database,
        ],
        [
            0 => $input,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (! is_resource($process)) {
        fclose($input);
        throw new RuntimeException('Unable to start the restore process.');
    }

    $standardOutput = stream_get_contents($pipes[1]);
    $standardError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Restore command failed: '
            . trim($standardError ?: $standardOutput)
        );
    }
}

function connectDatabase(
    string $host,
    int $port,
    string $username,
    string $password,
    string $database
): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    if ($connection->connect_errno !== 0) {
        throw new RuntimeException('Unable to connect to a comparison database.');
    }

    if (! $connection->set_charset('utf8mb4')) {
        throw new RuntimeException('Unable to configure the comparison charset.');
    }

    return $connection;
}

function compareDatabases(mysqli $source, mysqli $restore): void
{
    $sourceTables = tableNames($source);
    $restoreTables = tableNames($restore);

    if ($sourceTables !== $restoreTables) {
        throw new RuntimeException('The restored table list differs from the source.');
    }

    foreach ($sourceTables as $table) {
        $sourceSchema = createTableSql($source, $table);
        $restoreSchema = createTableSql($restore, $table);

        if ($sourceSchema !== $restoreSchema) {
            throw new RuntimeException("Table schema differs: {$table}");
        }

        $sourceRows = rowFingerprints($source, $table);
        $restoreRows = rowFingerprints($restore, $table);

        if ($sourceRows !== $restoreRows) {
            throw new RuntimeException("Table contents differ: {$table}");
        }

        echo sprintf(
            "Verified table: %s (%d rows)\n",
            $table,
            count($sourceRows)
        );
    }
}

function createFixture(mysqli $connection): void
{
    $createStatements = [
        'CREATE TABLE restore_fixture ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'utf8_text VARCHAR(255) NOT NULL,'
            . 'nullable_text VARCHAR(255) NULL,'
            . 'binary_data VARBINARY(255) NOT NULL,'
            . 'serialized_data LONGTEXT NOT NULL,'
            . 'PRIMARY KEY (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
            . 'COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE restore_fixture_empty ('
            . 'id BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
            . 'COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($createStatements as $sql) {
        if (! $connection->query($sql)) {
            throw new RuntimeException('Unable to create restore fixture tables.');
        }
    }

    $statement = $connection->prepare(
        'INSERT INTO restore_fixture '
        . '(utf8_text, nullable_text, binary_data, serialized_data) '
        . 'VALUES (?, ?, ?, ?)'
    );

    if ($statement === false) {
        throw new RuntimeException('Unable to prepare restore fixture data.');
    }

    $rows = [
        [
            '日本語のバックアップ 🚀',
            null,
            "\x00\x01\xff\x7f",
            serialize([
                'message' => '保存された設定 🗄️',
                'enabled' => true,
                'nullable' => null,
            ]),
        ],
        [
            '',
            '空文字とは異なる値',
            "\x00binary\x00data",
            serialize(false),
        ],
    ];

    foreach ($rows as $row) {
        [$utf8Text, $nullableText, $binaryData, $serializedData] = $row;

        if (
            ! $statement->bind_param(
                'ssss',
                $utf8Text,
                $nullableText,
                $binaryData,
                $serializedData
            )
            || ! $statement->execute()
        ) {
            throw new RuntimeException('Unable to insert restore fixture data.');
        }
    }

    $statement->close();
}
/**
 * @return list<string>
 */
function tableNames(mysqli $connection): array
{
    $result = $connection->query(
        "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
    );

    if ($result === false) {
        throw new RuntimeException('Unable to read the table list.');
    }

    $tables = [];

    while ($row = $result->fetch_row()) {
        $tables[] = (string) $row[0];
    }

    $result->free();
    sort($tables, SORT_STRING);

    return $tables;
}

function createTableSql(mysqli $connection, string $table): string
{
    $result = $connection->query(
        'SHOW CREATE TABLE ' . quoteIdentifier($table)
    );

    if ($result === false) {
        throw new RuntimeException("Unable to read table schema: {$table}");
    }

    $row = $result->fetch_row();
    $result->free();

    if (! isset($row[1]) || ! is_string($row[1])) {
        throw new RuntimeException("Invalid table schema result: {$table}");
    }

    return $row[1];
}

/**
 * @return list<string>
 */
function rowFingerprints(mysqli $connection, string $table): array
{
    $result = $connection->query(
        'SELECT * FROM ' . quoteIdentifier($table),
        MYSQLI_USE_RESULT
    );

    if ($result === false) {
        throw new RuntimeException("Unable to read table contents: {$table}");
    }

    $fingerprints = [];

    while ($row = $result->fetch_assoc()) {
        $fingerprints[] = hash('sha256', serialize($row));
    }

    $result->free();
    sort($fingerprints, SORT_STRING);

    return $fingerprints;
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function quoteOptionValue(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function removeFile(?string $path): void
{
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function fail(
    string $message,
    ?string $dumpPath = null,
    ?string $optionPath = null
): never {
    removeFile($optionPath);
    removeFile($dumpPath);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
