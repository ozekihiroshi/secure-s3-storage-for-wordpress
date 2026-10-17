<?php

// Local half of the real-S3 fixture test. Only a disposable localhost database.
define('ODBFS3_RESTORE_HELPERS_ONLY', true);
require __DIR__ . '/../tests/manual/test-database-restore.php';

use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\NativeMySqlDumper;
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

if (PHP_SAPI !== 'cli' || $argc !== 4 || ! in_array($argv[1], ['prepare', 'rehydrate', 'verify'], true)
    || ! ctype_digit($argv[3]) || (int) $argv[3] < 1024 || (int) $argv[3] > 65535) {
    throw new RuntimeException('Usage: prepare|rehydrate|verify PRIVATE_FIXTURE_DIRECTORY LOCAL_PORT');
}
$work = realpath($argv[2]);
if ($work === false || ! preg_match('#^/tmp/odbfs3-db-fixture\.[A-Za-z0-9]+$#D', $work)) {
    throw new RuntimeException('Dedicated fixture directory required.');
}
$port = (int) $argv[3];
// Public test-only password, never used for an existing site or server.
$password = 'odbfs3-disposable-fixture-only';
function saveFixtureJson(string $path, array $value): void {
    $out = SecureTemporaryFile::openForWriting($path);
    try {
        \SecureS3StorageForWordpress\Backup\CompleteStreamWriter::writeAll(json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", static fn ($s) => fwrite($out, $s), 'Fixture write failed');
    } finally { fclose($out); }
}
if ($argv[1] === 'prepare') {
    $admin = new mysqli('127.0.0.1', 'root', $password, '', $port);
    $info = ['format' => 'odbfs3-db-synthetic-fixture', 'version' => 1, 'run' => bin2hex(random_bytes(12)), 'backends' => []];
    foreach (['native', 'php'] as $backend) {
        $suffix = bin2hex(random_bytes(6));
        $sourceDb = 'secure_s3_restore_source_' . $suffix;
        $restoreDb = 'secure_s3_restore_test_' . $suffix;
        $admin->query('CREATE DATABASE ' . $sourceDb . ' CHARACTER SET utf8mb4');
        $admin->query('CREATE DATABASE ' . $restoreDb . ' CHARACTER SET utf8mb4');
        $source = connectDatabase('127.0.0.1', $port, 'root', $password, $sourceDb);
        createFixture($source);
        $source->close();
        $connection = new DatabaseConnection('127.0.0.1', $port, $sourceDb, 'root', $password);
        $dumper = $backend === 'native' ? new NativeMySqlDumper() : new PhpMySqlDumper();
        $dump = $dumper->dump($connection);
        try {
            $destination = $work . '/' . $backend . '.sql';
            $out = SecureTemporaryFile::openForWriting($destination);
            $in = fopen($dump->getPath(), 'rb');
            try {
                if (stream_copy_to_stream($in, $out) !== $dump->getSizeBytes()) { throw new RuntimeException('Fixture copy failed.'); }
            } finally { fclose($in); fclose($out); }
            clearstatcache(true, $destination);
            $info['backends'][$backend] = ['source_database' => $sourceDb, 'restore_database' => $restoreDb,
                'sql_size' => filesize($destination), 'sql_sha256' => hash_file('sha256', $destination)];
        } finally { unlink($dump->getPath()); }
    }
    $admin->close();
    saveFixtureJson($work . '/fixture.json', $info);
    echo 'Prepared synthetic fixture run=' . $info['run'] . "\n";
} elseif ($argv[1] === 'rehydrate') {
    // Rebuild the deterministic reference, never from downloaded backup data.
    // CREATE DATABASE fails closed if a schema already exists.
    $info = json_decode(file_get_contents($work . '/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($info['format'] ?? '') !== 'odbfs3-db-synthetic-fixture') { throw new RuntimeException('Invalid fixture.'); }
    $admin = new mysqli('127.0.0.1', 'root', $password, '', $port);
    foreach (['native', 'php'] as $backend) {
        $entry = $info['backends'][$backend];
        if (! preg_match('/^secure_s3_restore_source_[a-f0-9]{12}$/D', $entry['source_database'])
            || ! preg_match('/^secure_s3_restore_test_[a-f0-9]{12}$/D', $entry['restore_database'])) { throw new RuntimeException('Unsafe fixture DB name.'); }
        $admin->query('CREATE DATABASE ' . $entry['source_database'] . ' CHARACTER SET utf8mb4');
        $admin->query('CREATE DATABASE ' . $entry['restore_database'] . ' CHARACTER SET utf8mb4');
        $source = connectDatabase('127.0.0.1', $port, 'root', $password, $entry['source_database']);
        createFixture($source);
        $source->close();
    }
    $admin->close();
    echo "Rehydrated deterministic reference in disposable database.\n";
} else {
    $info = json_decode(file_get_contents($work . '/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    $evidence = json_decode(file_get_contents($work . '/s3-result.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($info['format'] ?? '') !== 'odbfs3-db-synthetic-fixture' || $evidence['run'] !== $info['run']) { throw new RuntimeException('Fixture evidence mismatch.'); }
    foreach (['native', 'php'] as $backend) {
        $entry = $info['backends'][$backend];
        if (! preg_match('/^secure_s3_restore_source_[a-f0-9]{12}$/D', $entry['source_database'])
            || ! preg_match('/^secure_s3_restore_test_[a-f0-9]{12}$/D', $entry['restore_database'])) { throw new RuntimeException('Unsafe fixture DB name.'); }
        $sql = $work . '/' . $backend . '-download.sql';
        if (filesize($sql) !== $entry['sql_size'] || ! hash_equals($entry['sql_sha256'], hash_file('sha256', $sql))) {
            throw new RuntimeException('Downloaded SQL does not match original local evidence.');
        }
        $source = connectDatabase('127.0.0.1', $port, 'root', $password, $entry['source_database']);
        $restore = connectDatabase('127.0.0.1', $port, 'root', $password, $entry['restore_database']);
        if (tableNames($restore) !== []) { throw new RuntimeException('Restore destination is not empty.'); }
        $option = null;
        try { importDump('127.0.0.1', $port, 'root', $password, $entry['restore_database'], $sql, $option); }
        finally { removeFile($option); }
        compareDatabases($source, $restore);
        $source->close(); $restore->close();
        echo 'PASS real-S3 isolated import backend=' . $backend . "\n";
    }
    saveFixtureJson($work . '/restore-result.json', ['run' => $info['run'], 'result' => 'real_s3_restore_matches_original_fixture', 'backends' => ['native', 'php'], 'tested_at' => gmdate(DATE_ATOM)]);
}
