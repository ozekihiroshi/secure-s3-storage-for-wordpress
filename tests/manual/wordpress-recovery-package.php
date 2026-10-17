<?php

use SecureS3StorageForWordpress\Backup\Recovery\RecoveryManifest;
use SecureS3StorageForWordpress\Backup\Media\{MediaManifest, MediaEntry};
use SecureS3StorageForWordpress\Backup\Compression\GzipVerifier;

/** Test-only bounded staging. No SQL execution; no production restore command. */
function fixturePackage(array $locator, string $stage, callable $objectPath): array
{
    if (realpath($stage) !== $stage || is_link($stage) || scandir($stage) !== ['.', '..']) { throw new RuntimeException('Empty private staging required.'); }
    $fetch = static function (string $key, int $size, string $hash, string $output) use ($locator, $objectPath): void {
        if ($size < 0 || $size > 16777216) { throw new RuntimeException('Fixture object size limit.'); }
        $path = $objectPath($locator['bucket'], $key);
        if (is_link($path) || ! is_file($path) || filesize($path) !== $size || hash_file('sha256', $path) !== $hash) {
            throw new RuntimeException('Missing or corrupt fixture object.');
        }
        $out = fopen($output, 'xb'); $in = fopen($path, 'rb');
        try {
            if (stream_copy_to_stream($in, $out, $size + 1) !== $size) { throw new RuntimeException('Object copy failed.'); }
        } finally { fclose($in); fclose($out); }
        if (hash_file('sha256', $output) !== $hash) { throw new RuntimeException('Copied object changed.'); }
    };
    if ($locator['size'] > 65536) { throw new RuntimeException('Manifest size limit.'); }
    $fetch($locator['key'], $locator['size'], $locator['sha256'], $stage . '/manifest.json');
    $manifest = RecoveryManifest::decode(file_get_contents($stage . '/manifest.json'));
    $m = $manifest->toArray();
    if ($m['destination']['bucket'] !== $locator['bucket'] || $m['profile'] !== 'database_uploads'
        || $locator['key'] !== ($m['destination']['prefix'] === '' ? '' : $m['destination']['prefix'] . '/') . 'backups/recovery-sets/' . $m['set_id'] . '/manifest.json') {
        throw new RuntimeException('Wrong recovery identity.');
    }
    foreach (['database' => $m['database']['artifact'], 'inventory' => $m['media']['inventory'], 'marker' => $m['media']['marker']] as $name => $a) {
        $fetch($a['key'], $a['size_bytes'], $a['sha256'], $stage . '/' . $name);
    }
    if (json_decode(file_get_contents($stage . '/marker'), true, 8, JSON_THROW_ON_ERROR) !== $m['media']['marker_evidence']) { throw new RuntimeException('Marker mismatch.'); }
    $raw = $m['database']['raw_sql'];
    if ($raw['size_bytes'] > 67108864) { throw new RuntimeException('Fixture SQL limit.'); }
    $verified = (new GzipVerifier())->verify($stage . '/database', $raw['size_bytes'], $stage . '/database.sql');
    if ($verified['sizeBytes'] !== $raw['size_bytes'] || $verified['sha256'] !== $raw['sha256']) { throw new RuntimeException('Raw SQL integrity mismatch.'); }
    // Consume the inventory footer before placing any extracted file.
    $entries = iterator_to_array((new MediaManifest())->entries($stage . '/inventory'));
    if (count($entries) !== $m['media']['file_count'] || array_sum(array_map(static fn ($e) => $e->size, $entries)) !== $m['media']['total_bytes']) {
        throw new RuntimeException('Inventory totals mismatch.');
    }
    mkdir($stage . '/uploads', 0700);
    foreach ($entries as $entry) {
        MediaEntry::validatePath($entry->path);
        $destination = $stage . '/uploads/' . $entry->path;
        $directory = dirname($destination);
        if (! is_dir($directory)) { mkdir($directory, 0700, true); }
        if (realpath($directory) !== $directory || ! str_starts_with($directory . '/', $stage . '/uploads/')) { throw new RuntimeException('Unsafe extraction directory.'); }
        $fetch($m['media']['prefix'] . 'files/' . hash('sha256', $entry->path), $entry->size, $entry->sha256, $destination);
    }
    return [$manifest, $entries];
}
