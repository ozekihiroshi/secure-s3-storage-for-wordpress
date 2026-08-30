<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use Generator;
use RuntimeException;

/** Read-only inventory of one trusted, administrator-selected local upload root. */
final class MediaSource
{
    private string $root;
    private array $rootIdentity;

    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');
        if (preg_match('/^[A-Za-z]:$/D', $root)) {
            throw new RuntimeException('A filesystem root cannot be a media root.');
        }
        if ($root === '' || str_contains($root, "\0") || is_link($root)) {
            throw new RuntimeException('Invalid media root.');
        }
        $canonical = realpath($root);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException('Media root does not exist.');
        }
        $this->root = rtrim(str_replace('\\', '/', $canonical), '/');
        if ($this->root === '' || preg_match('/^[A-Za-z]:$/D', $this->root)) {
            throw new RuntimeException('A filesystem root cannot be a media root.');
        }
        $this->rootIdentity = $this->stat($this->root);
    }

    /** @return Generator<int, MediaEntry> */
    public function entries(?callable $onChunk = null): Generator
    {
        yield from $this->walk('', $onChunk);
    }

    /** Resolve a work/output parent and reject storage inside the input tree. */
    public function externalDirectory(string $directory): string
    {
        $canonical = realpath($directory);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException('Media work directory does not exist.');
        }
        $canonical = rtrim(str_replace('\\', '/', $canonical), '/');
        $root = $this->root;
        $candidate = $canonical;
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }
        if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
            throw new RuntimeException('Media artifacts must be outside the upload root.');
        }
        return $canonical === '' ? '/' : $canonical;
    }

    private function walk(string $relative, ?callable $onChunk): Generator
    {
        $path = $this->resolve($relative);
        $before = $this->stat($path);
        if (($before['mode'] & 0170000) !== 0040000 || ! is_readable($path)) {
            throw new RuntimeException('Media directory is not readable.');
        }
        // Directory streaming avoids loading a large directory into memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
        $directory = @opendir($path);
        if ($directory === false) {
            throw new RuntimeException('Unable to enumerate media directory.');
        }
        try {
            while (false !== ($name = readdir($directory))) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $relative === '' ? $name : $relative . '/' . $name;
                MediaEntry::validatePath($child);
                $childPath = $this->resolve($child);
                $snapshot = $this->stat($childPath);
                $kind = $snapshot['mode'] & 0170000;
                if ($kind === 0040000) {
                    yield from $this->walk($child, $onChunk);
                } elseif ($kind === 0100000) {
                    yield $this->checksum($child, $snapshot, $onChunk);
                } else {
                    throw new RuntimeException('Unsupported media filesystem entry.');
                }
            }
            $this->assertSame($before, $this->stat($this->resolve($relative)));
        } finally {
            closedir($directory);
        }
    }

    private function checksum(string $relative, array $snapshot, ?callable $onChunk): MediaEntry
    {
        $path = $this->resolve($relative);
        if (! is_readable($path) || ($snapshot['mode'] & 0444) === 0) {
            throw new RuntimeException('Media file is not readable.');
        }
        // Only regular files are accepted; links/devices/FIFOs never intentionally open.
        $this->assertSame($snapshot, $this->stat($path));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read media file.');
        }
        try {
            $this->assertSame($snapshot, $this->streamStat($stream));
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $chunk = fread($stream, 1048576);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('Unable to read media file.');
                }
                $bytes += strlen($chunk);
                if (! is_int($bytes) || $bytes > $snapshot['size']) {
                    throw new RuntimeException('Media file changed during inventory.');
                }
                hash_update($hash, $chunk);
                if ($onChunk !== null && $chunk !== '') {
                    $onChunk($relative, $bytes);
                }
            }
            $this->assertSame($snapshot, $this->streamStat($stream));
            $this->assertSame($snapshot, $this->stat($this->resolve($relative)));
            if ($bytes !== $snapshot['size']) {
                throw new RuntimeException('Media file changed during inventory.');
            }
            return new MediaEntry($relative, $bytes, hash_final($hash));
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    private function resolve(string $relative): string
    {
        $root = $this->stat($this->root);
        foreach (['dev', 'ino', 'mode'] as $field) {
            if ($root[$field] !== $this->rootIdentity[$field]) {
                throw new RuntimeException('Media root changed during inventory.');
            }
        }
        $path = $this->root;
        if ($relative !== '') {
            MediaEntry::validatePath($relative);
            foreach (explode('/', $relative) as $part) {
                $path .= '/' . $part;
                $this->stat($path); // Check every component, not only the final file.
            }
        }
        return $path;
    }

    private function stat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) === 0120000) {
            throw new RuntimeException('Media path is missing or is a symbolic link.');
        }
        if (($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] > 1) {
            throw new RuntimeException('Hard-linked media files are not supported.');
        }
        return $stat;
    }

    private function assertSame(array $expected, array $actual): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($expected[$field] !== $actual[$field]) {
                throw new RuntimeException('Media path changed during inventory.');
            }
        }
    }

    /** @param resource $stream */
    private function streamStat($stream): array
    {
        $stat = fstat($stream);
        if ($stat === false) {
            throw new RuntimeException('Unable to inspect open media file.');
        }
        return $stat;
    }
}
