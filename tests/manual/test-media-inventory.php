<?php

use SecureS3StorageForWordpress\Backup\Media\MediaEntry;
use SecureS3StorageForWordpress\Backup\Media\MediaInventorySorter;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;

// All fixtures live in a new private OS-temp directory; never pass site paths.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecureS3StorageForWordpress\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$base = sys_get_temp_dir() . '/odbfs3-media-test-' . bin2hex(random_bytes(12));
if (! mkdir($base, 0700)) { throw new RuntimeException('Cannot create fixture.'); }
$root = $base . '/uploads';
$restore = $base . '/restored';
$work = $base . '/work';
foreach ([$root, $restore, $work] as $directory) { mkdir($directory, 0700); }
$checks = 0;
$exitCode = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$throws = static function (callable $callback, string $message) use ($check): void {
    try { $callback(); } catch (Throwable $e) { $check(true, $message); return; }
    $check(false, $message);
};
$exhaust = static function (iterable $entries): void { foreach ($entries as $entry) {} };
$cleanWork = static function () use ($work, $check): void {
    $check(count(scandir($work)) === 2, 'Sort work must be cleaned.');
};

try {
    // A tiny sort budget intentionally exercises many spills and merge levels.
    $manifest = new MediaManifest(new MediaInventorySorter(150));
    $inventory = $base . '/inventory.jsonl';
    $summary = $manifest->create(new MediaSource($root), $inventory, $work);
    $check($summary['files'] === 0 && $summary['bytes'] === 0, 'Empty inventory.');
    $check($manifest->verify(new MediaSource($restore), $inventory, $work)->successful(), 'Empty restore.');
    unlink($inventory);

    $files = [
        'z.pdf' => "%PDF-test\0\1",
        '.hidden' => 'hidden metadata',
        'zero.bin' => '',
        '2026/08/photo.jpg' => 'original image',
        '2026/08/photo-150x150.jpg' => 'thumbnail',
        '2026/日本語 😀.png' => 'Unicode path',
        "quoted-\"-line\n.txt" => 'JSON escaping',
        'a' => 'file before directory',
        'a-dir/b.txt' => 'nested',
    ];
    foreach ($files as $relative => $content) {
        foreach ([$root, $restore] as $target) {
            if (! is_dir(dirname($target . '/' . $relative))) { mkdir(dirname($target . '/' . $relative), 0700, true); }
            file_put_contents($target . '/' . $relative, $content);
        }
    }
    $summary = $manifest->create(new MediaSource($root), $inventory, $work);
    $check($summary['files'] === count($files), 'All originals, hidden files and thumbnails included.');
    $check($summary['bytes'] === array_sum(array_map('strlen', $files)), 'Byte total.');
    if (DIRECTORY_SEPARATOR !== '\\') {
        $check((fileperms($inventory) & 0777) === 0600, 'Private inventory permissions.');
    }
    $entries = iterator_to_array($manifest->entries($inventory));
    $paths = array_map(static fn ($entry) => $entry->path, $entries);
    $sortedPaths = array_keys($files);
    usort($sortedPaths, 'strcmp');
    $check($paths === $sortedPaths, 'Deterministic bytewise path order.');
    foreach ($entries as $entry) {
        $check($entry->sha256 === hash('sha256', $files[$entry->path]), 'Standard full-file SHA-256.');
    }
    $check(! str_contains(file_get_contents($inventory), $base), 'No absolute host paths stored.');
    $result = $manifest->verify(new MediaSource($restore), $inventory, $work);
    $check($result->successful() && $result->matched === count($files), 'Restored file verification.');
    $cleanWork();

    // Same-size corruption must be detected by SHA-256, not just length.
    file_put_contents($restore . '/a', str_repeat('X', strlen($files['a'])));
    unlink($restore . '/zero.bin');
    file_put_contents($restore . '/unexpected.bin', 'extra');
    $result = $manifest->verify(new MediaSource($restore), $inventory, $work);
    $check(! $result->successful(), 'Mismatched restore fails.');
    $check($result->changed === 1 && $result->missing === 1 && $result->unexpected === 1, 'Classify restore differences.');
    $check($result->matched === count($files) - 2, 'Matched count after differences.');
    $cleanWork();

    $before = file_get_contents($inventory);
    $throws(static fn () => $manifest->create(new MediaSource($root), $inventory, $work), 'Existing output must not be overwritten.');
    $check(file_get_contents($inventory) === $before, 'Existing manifest preserved.');
    $throws(static fn () => $manifest->create(new MediaSource($root), $root . '/self.jsonl', $work), 'Do not inventory the output itself.');
    $throws(static fn () => $manifest->create(new MediaSource($root), $base . '/inside-work.jsonl', $root), 'Work cannot be inside source.');
    $check(! file_exists($root . '/self.jsonl'), 'No artifact created in source.');

    foreach (['../secret', '/absolute', 'a/../b', './a', 'a//b', 'a/', "a\0b", 'a\\b', 'C:secret', "bad\xff"] as $badPath) {
        $throws(static fn () => new MediaEntry($badPath, 0, hash('sha256', '')), 'Unsafe path rejected.');
    }
    $throws(static fn () => MediaEntry::decode('{"path":"a","size":"1","sha256":"' . str_repeat('a', 64) . '"}'), 'No size coercion.');
    $throws(static fn () => new MediaEntry('a', -1, str_repeat('a', 64)), 'Negative size rejected.');
    $throws(static fn () => new MediaEntry('a', 1, 'bad-hash'), 'Invalid checksum rejected.');
    $throws(static fn () => new MediaSource('/'), 'Filesystem root rejected.');
    $throws(static fn () => new MediaSource($base . '/absent'), 'Missing root is not a successful empty backup.');

    $lines = explode("\n", rtrim($before, "\n"));
    $invalids = [
        implode("\n", array_slice($lines, 0, -1)) . "\n", // Missing footer.
        substr($before, 0, -2),
        $before . "{}\n",
        str_replace('"version":1', '"version":99', $before),
        str_replace($entries[0]->sha256, str_repeat('f', 64), $before),
        $lines[0] . "\n" . $lines[1] . "\n" . $lines[1] . "\n" . end($lines) . "\n",
        $lines[0] . "\n" . str_repeat('x', 65537) . "\n",
        $lines[0] . "\n{\"path\":\"../escape\",\"size\":0,\"sha256\":\"" . hash('sha256', '') . "\"}\n",
    ];
    $badManifest = $base . '/bad.jsonl';
    foreach ($invalids as $badContent) {
        file_put_contents($badManifest, $badContent);
        $throws(static fn () => $exhaust($manifest->entries($badManifest)), 'Corrupt inventory rejected.');
    }
    $throws(static fn () => $manifest->verify(new MediaSource($restore), $badManifest, $work), 'Corrupt manifest cannot report success.');
    $cleanWork();

    $duplicate = [new MediaEntry('same', 0, hash('sha256', '')), new MediaEntry('same', 0, hash('sha256', ''))];
    foreach ([1, 10000] as $budget) {
        $throws(static fn () => $exhaust((new MediaInventorySorter($budget))->sorted($duplicate, $work)), 'Duplicates rejected across/within runs.');
        $cleanWork();
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        $outside = $base . '/outside.txt';
        file_put_contents($outside, 'must not read/delete this');
        foreach ([$outside, $root . '/z.pdf', $base . '/missing-target', $root . '/2026'] as $target) {
            symlink($target, $root . '/link');
            $output = $base . '/failed.jsonl';
            $throws(static fn () => $manifest->create(new MediaSource($root), $output, $work), 'Reject external/internal/dangling/directory symlink.');
            $check(! file_exists($output), 'Failed inventory removed.');
            unlink($root . '/link');
            $cleanWork();
        }
        $check(file_get_contents($outside) === 'must not read/delete this', 'Outside file untouched.');
        symlink($root, $base . '/root-link');
        $throws(static fn () => new MediaSource($base . '/root-link'), 'Root symlink rejected.');
        $throws(static fn () => new MediaSource($base . '/root-link/'), 'Root symlink with slash rejected.');
        unlink($base . '/root-link');
        link($outside, $root . '/hardlink');
        $throws(static fn () => $exhaust((new MediaSource($root))->entries()), 'Hardlink rejected.');
        unlink($root . '/hardlink');
        chmod($root . '/z.pdf', 0000);
        $throws(static fn () => $exhaust((new MediaSource($root))->entries()), 'Unreadable file fails, not skipped.');
        chmod($root . '/z.pdf', 0600);
        mkdir($root . '/unreadable', 0700);
        chmod($root . '/unreadable', 0000);
        $throws(static fn () => $exhaust((new MediaSource($root))->entries()), 'Unreadable directory fails, not skipped.');
        chmod($root . '/unreadable', 0700);
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($root . '/pipe', 0600);
            $throws(static fn () => $exhaust((new MediaSource($root))->entries()), 'FIFO rejected without opening.');
            unlink($root . '/pipe');
        }
    }

    // File larger than the PHP memory budget; generate it in small chunks.
    $largeRoot = $base . '/large';
    mkdir($largeRoot, 0700);
    $large = $largeRoot . '/video.bin';
    $handle = fopen($large, 'wb');
    $chunk = str_repeat('video-fixture-', 8192);
    $expectedHash = hash_init('sha256');
    $expectedSize = 0;
    for ($i = 0; $i < 384; ++$i) {
        fwrite($handle, $chunk);
        hash_update($expectedHash, $chunk);
        $expectedSize += strlen($chunk);
    }
    fclose($handle);
    unset($chunk);
    $callbackCount = 0;
    $summary = $manifest->create(new MediaSource($largeRoot), $base . '/large.jsonl', $work,
        static function () use (&$callbackCount, $base, $check): void {
            ++$callbackCount;
            if ($callbackCount === 1 && DIRECTORY_SEPARATOR !== '\\') {
                $check((fileperms($base . '/large.jsonl') & 0777) === 0600, 'Inventory private before file data is read.');
            }
        });
    $check($summary['bytes'] === $expectedSize && $callbackCount > 1, 'Large file read in bounded chunks.');
    $entry = iterator_to_array($manifest->entries($base . '/large.jsonl'))[0];
    $check($entry->sha256 === hash_final($expectedHash), 'Large-file SHA-256.');

    $changedOnce = false;
    $throws(static fn () => $manifest->create(new MediaSource($largeRoot), $base . '/changed.jsonl', $work,
        static function () use ($large, &$changedOnce): void {
            if (! $changedOnce) { file_put_contents($large, 'appended', FILE_APPEND); $changedOnce = true; }
        }), 'File growth during hashing rejected.');
    $check(! file_exists($base . '/changed.jsonl'), 'Changed-file inventory not published.');
    $cleanWork();

    $changedOnce = false;
    $throws(static fn () => $manifest->create(new MediaSource($largeRoot), $base . '/dir-change.jsonl', $work,
        static function () use ($largeRoot, &$changedOnce): void {
            if (! $changedOnce) { touch($largeRoot, time() - 100); $changedOnce = true; }
        }), 'Directory change during traversal rejected.');
    $cleanWork();

    $changedOnce = false;
    $throws(static fn () => $manifest->create(new MediaSource($largeRoot), $base . '/replaced.jsonl', $work,
        static function () use ($large, &$changedOnce): void {
            if (! $changedOnce) { rename($large, $large . '.old'); file_put_contents($large, 'replacement'); $changedOnce = true; }
        }), 'Replacement during hashing rejected.');
    $cleanWork();
    $throws(static fn () => $manifest->create(new MediaSource($root), $base . '/cancelled.jsonl', $work,
        static function (): void { throw new RuntimeException('Cancelled by worker budget.'); }), 'Worker cancellation propagates.');
    $check(! file_exists($base . '/cancelled.jsonl'), 'Cancelled inventory removed.');
    $cleanWork();

    // Many records, produced lazily; no array of the full inventory.
    $many = static function (): Generator {
        for ($i = 24999; $i >= 0; --$i) {
            yield new MediaEntry(sprintf('media/%08d.jpg', $i), $i, hash('sha256', (string) $i));
        }
    };
    $count = 0;
    foreach ((new MediaInventorySorter(32768))->sorted($many(), $work) as $entry) {
        if ($entry->path !== sprintf('media/%08d.jpg', $count)) { throw new RuntimeException('Large inventory order.'); }
        ++$count;
    }
    $check($count === 25000, 'Many-file external sort.');
    $cleanWork();
    $count = 0;
    foreach ((new MediaInventorySorter())->sorted($many(), $work) as $entry) {
        if ($entry->path !== sprintf('media/%08d.jpg', $count)) { throw new RuntimeException('Default-budget inventory order.'); }
        ++$count;
    }
    $check($count === 25000, 'Default-budget external sort.');
    $cleanWork();

    $check(memory_get_peak_usage(true) < 32 * 1024 * 1024, 'Bounded memory below 32 MiB.');
    echo "Media inventory verification: OK ($checks checks; peak " . memory_get_peak_usage(true) . " bytes)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Media inventory verification failed: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    // Delete only the exact, generated fixture directory; never follow symlinks.
    $resolved = realpath($base);
    $parent = realpath(sys_get_temp_dir());
    if ($resolved !== false && dirname($resolved) === $parent && preg_match('/^odbfs3-media-test-[a-f0-9]{24}$/D', basename($resolved))) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) { rmdir($item->getPathname()); }
            else { unlink($item->getPathname()); }
        }
        rmdir($resolved);
    }
}
exit($exitCode);
