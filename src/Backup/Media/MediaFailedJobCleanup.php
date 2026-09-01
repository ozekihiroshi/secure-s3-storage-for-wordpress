<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;

/** Exact, fail-closed removal for a failed job's private generated workspace. */
final class MediaFailedJobCleanup
{
    /** @param list<string> $forbiddenRoots @return array<string, int> */
    public static function captureIdentity(string $directory, array $forbiddenRoots): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new RuntimeException('Media cleanup requires POSIX filesystem permissions.');
        }
        $canonical = realpath($directory);
        if ($canonical === false || rtrim($directory, '/') !== $canonical
            || preg_match('/^odbfs3-(?:preparation-)?[a-f0-9]{32}$/D', basename($canonical)) !== 1) {
            throw new RuntimeException('Media cleanup requires an exact generated workspace.');
        }
        foreach ($forbiddenRoots as $root) {
            $root = realpath($root);
            if ($root === false) { throw new RuntimeException('Media cleanup boundary is unavailable.'); }
            $root = rtrim($root, '/');
            if ($canonical === $root || str_starts_with($canonical, $root . '/')) {
                throw new RuntimeException('Media cleanup workspace is inside a protected root.');
            }
        }
        self::assertAncestors($canonical);
        return self::inspectDirectory($canonical);
    }

    /** Missing after a recorded pending cleanup is already removed and therefore idempotent. */
    public static function remove(string $directory, array $identity): void
    {
        self::assertIdentityShape($identity);
        clearstatcache(true, $directory);
        if (@lstat($directory) === false) { return; }
        if (rtrim($directory, '/') !== realpath($directory)
            || self::inspectDirectory($directory) !== $identity) {
            throw new RuntimeException('Media cleanup workspace identity changed.');
        }
        $lock = null;
        $lockPath = $directory . '/worker.lock';
        if (@lstat($lockPath) !== false) {
            self::assertFile($lockPath, $identity['uid']);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $lock = fopen($lockPath, 'rb');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($lock);
                }
                throw new RuntimeException('Media cleanup workspace is still in use.');
            }
            self::assertFile($lockPath, $identity['uid'], fstat($lock));
        }
        try {
            self::validateEntries($directory, $identity['uid']);
            self::deleteEntries($directory, $identity['uid']);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($lock);
            }
        }
        clearstatcache(true, $directory);
        if (@lstat($directory) === false) { return; }
        if (self::inspectDirectory($directory) !== $identity) {
            throw new RuntimeException('Media cleanup workspace changed before removal.');
        }
        // The exact, now-empty generated directory only; never a recursive path.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (! rmdir($directory)) {
            throw new RuntimeException('Unable to remove media cleanup workspace.');
        }
    }

    private static function validateEntries(string $directory, int $uid): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
        $stream = opendir($directory);
        if ($stream === false) { throw new RuntimeException('Unable to inspect media cleanup workspace.'); }
        try {
            while (($name = readdir($stream)) !== false) {
                if ($name === '.' || $name === '..') { continue; }
                if (! self::allowedName($name)) {
                    throw new RuntimeException('Unexpected file in media cleanup workspace.');
                }
                self::assertFile($directory . '/' . $name, $uid);
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir
            closedir($stream);
        }
    }

    private static function deleteEntries(string $directory, int $uid): void
    {
        // A second pass prevents deletion before every entry has passed the allowlist.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
        $stream = opendir($directory);
        if ($stream === false) { throw new RuntimeException('Unable to open media cleanup workspace.'); }
        try {
            while (($name = readdir($stream)) !== false) {
                if ($name === '.' || $name === '..') { continue; }
                $path = $directory . '/' . $name;
                if (! self::allowedName($name)) {
                    throw new RuntimeException('Media cleanup workspace changed.');
                }
                self::assertFile($path, $uid);
                // Only a validated regular file in the exact recorded workspace.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if (! unlink($path)) { throw new RuntimeException('Unable to remove media cleanup file.'); }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir
            closedir($stream);
        }
    }

    private static function allowedName(string $name): bool
    {
        return in_array($name, [
            'worker.lock', 'directories.jsonl', 'paths.jsonl', 'part-hashes.txt',
            'inventory.jsonl', 'objects.jsonl', 'ready.json',
        ], true)
            || preg_match('/^[a-f0-9]{32}\\.json$/D', $name) === 1
            || preg_match('/^sort-[0-9]+-[0-9]+\\.jsonl$/D', $name) === 1
            || preg_match('/^runs-[0-9]+\\.jsonl$/D', $name) === 1;
    }

    /** @return array<string, int> */
    private static function inspectDirectory(string $directory): array
    {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0040700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Media cleanup workspace must be owned and private.');
        }
        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'],
            'uid' => $stat['uid'], 'gid' => $stat['gid']];
    }

    private static function assertAncestors(string $directory): void
    {
        $path = $directory;
        do {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Media cleanup path contains a link or non-directory.');
            }
            $path = dirname($path);
        } while ($path !== '/');
    }

    private static function assertIdentityShape(array $identity): void
    {
        if (array_keys($identity) !== ['dev', 'ino', 'mode', 'uid', 'gid']) {
            throw new RuntimeException('Invalid media cleanup workspace identity.');
        }
        foreach ($identity as $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Invalid media cleanup workspace identity.');
            }
        }
    }

    /** @param array<string, mixed>|false|null $opened */
    private static function assertFile(string $path, int $uid, array|false|null $opened = null): void
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        if ($named === false || ($named['mode'] & 0177777) !== 0100600
            || $named['nlink'] !== 1 || $named['uid'] !== $uid) {
            throw new RuntimeException('Media cleanup entry is not a private regular file.');
        }
        if (is_array($opened) && ($opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino'])) {
            throw new RuntimeException('Media cleanup lock identity changed.');
        }
    }
}
