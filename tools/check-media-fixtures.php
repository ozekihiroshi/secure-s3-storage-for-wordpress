<?php

// Read source data only. Write new inventory/sort artifacts in the fixture root.
if (PHP_SAPI !== 'cli') { exit; }
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use SecureS3StorageForWordpress\Backup\Media\MediaEntry;
use SecureS3StorageForWordpress\Backup\Media\MediaInventoryIO;
use SecureS3StorageForWordpress\Backup\Media\MediaInventorySorter;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;

try {
    if ($argc !== 2 || $argv[1] === '--help') {
        echo "Usage: php tools/check-media-fixtures.php FIXTURE_DIRECTORY\n";
        exit($argc === 2 ? 0 : 1);
    }
    $root = realpath($argv[1]);
    if ($root === false || ! is_dir($root)) { throw new RuntimeException('Fixture directory not found.'); }
    $info = json_decode(file_get_contents($root . '/fixture-info.json'), true, 8, JSON_THROW_ON_ERROR);
    if (($info['format'] ?? '') !== 'odbfs3-synthetic-fixture' || ($info['version'] ?? null) !== 1
        || ! is_int($info['files'] ?? null) || ! is_int($info['bytes'] ?? null)
        || ! is_string($info['expected_sha256'] ?? null)
        || ! hash_equals($info['expected_sha256'], hash_file('sha256', $root . '/expected.jsonl'))) {
        throw new RuntimeException('Fixture completion record or expected checksum file is invalid.');
    }
    $started = microtime(true);
    $manifestPath = $root . '/inventory-' . bin2hex(random_bytes(8)) . '.jsonl';
    $manifest = new MediaManifest();
    $summary = $manifest->create(new MediaSource($root . '/uploads'), $manifestPath, $root);
    $expectedEntries = static function () use ($root): Generator {
        $stream = MediaInventoryIO::openRead($root . '/expected.jsonl');
        try {
            while (($line = MediaInventoryIO::readLine($stream)) !== null) { yield MediaEntry::decode($line); }
        } finally { fclose($stream); }
    };
    $expected = (new MediaInventorySorter())->sorted($expectedEntries(), $root);
    $actual = $manifest->entries($manifestPath);
    $matched = 0;
    while ($expected->valid() || $actual->valid()) {
        if (! $expected->valid() || ! $actual->valid() || $expected->current()->encode() !== $actual->current()->encode()) {
            throw new RuntimeException('Generated fixture checksums do not match the inventory.');
        }
        ++$matched;
        $expected->next();
        $actual->next();
    }
    if ($matched !== $info['files'] || $summary['files'] !== $info['files'] || $summary['bytes'] !== $info['bytes']) {
        throw new RuntimeException('Fixture file count or size does not match.');
    }
    echo 'inventory=' . $manifestPath . PHP_EOL;
    echo 'files=' . $matched . PHP_EOL . 'bytes=' . $summary['bytes'] . PHP_EOL;
    echo 'elapsed_seconds=' . round(microtime(true) - $started, 3) . PHP_EOL;
    echo 'php_peak_bytes=' . memory_get_peak_usage(true) . PHP_EOL;
    echo "result=inventory_matches_fixture\n";
    echo "No S3 upload, WordPress media registration or DB restore was performed.\n";
} catch (Throwable $e) {
    // Explicitly release generator-owned work files before exit.
    unset($expected, $actual);
    fwrite(STDERR, 'Fixture check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
