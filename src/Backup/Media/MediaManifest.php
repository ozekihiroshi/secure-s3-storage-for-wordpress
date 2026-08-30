<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use Generator;
use RuntimeException;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** A local inventory, NOT evidence that S3 upload has completed. */
final class MediaManifest
{
    private const HEADER = ['format' => 'odbfs3-media-inventory', 'version' => 1, 'algorithm' => 'sha256'];

    public function __construct(private ?MediaInventorySorter $sorter = null)
    {
        $this->sorter ??= new MediaInventorySorter();
    }

    /** @return array{files: int, bytes: int, sha256: string} */
    public function create(MediaSource $source, string $destination, string $workParent, ?callable $onChunk = null): array
    {
        $source->externalDirectory(dirname($destination));
        $parent = $source->externalDirectory($workParent);
        $stream = SecureTemporaryFile::openForWriting($destination);
        $identity = fstat($stream);
        $complete = false;
        try {
            $hash = hash_init('sha256');
            $header = json_encode(self::HEADER, JSON_THROW_ON_ERROR) . "\n";
            MediaInventoryIO::write($stream, $header);
            hash_update($hash, $header);
            $files = 0;
            $bytes = 0;
            foreach ($this->sorter->sorted($source->entries($onChunk), $parent) as $entry) {
                $line = $entry->encode();
                MediaInventoryIO::write($stream, $line);
                hash_update($hash, $line);
                ++$files;
                $bytes = $this->addBytes($bytes, $entry->size);
            }
            $summary = ['files' => $files, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
            MediaInventoryIO::write($stream, json_encode(['type' => 'inventory_end'] + $summary, JSON_THROW_ON_ERROR) . "\n");
            MediaInventoryIO::finish($stream);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if (! fclose($stream)) {
                throw new RuntimeException('Unable to close media inventory.');
            }
            $complete = true;
            return $summary;
        } finally {
            if (is_resource($stream)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($stream);
            }
            if (! $complete) {
                clearstatcache(true, $destination);
                $current = @lstat($destination);
                if ($identity !== false && $current !== false
                    && $current['dev'] === $identity['dev'] && $current['ino'] === $identity['ino']) {
                    // Remove only our own incomplete artifact, not any pre-existing file.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink($destination);
                }
            }
        }
    }

    /**
     * @return Generator<int, MediaEntry>
     * Must be consumed to EOF before trusting the digest/totals. No extraction.
     */
    public function entries(string $manifest): Generator
    {
        $stream = MediaInventoryIO::openRead($manifest);
        try {
            $line = MediaInventoryIO::readLine($stream);
            if ($line === null || json_decode($line, true, 8, JSON_THROW_ON_ERROR) !== self::HEADER) {
                throw new RuntimeException('Unsupported media inventory format.');
            }
            $hash = hash_init('sha256');
            hash_update($hash, $line);
            $files = 0;
            $bytes = 0;
            $previous = null;
            while (($line = MediaInventoryIO::readLine($stream)) !== null) {
                $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($record) && ($record['type'] ?? null) === 'inventory_end') {
                    if (count($record) !== 4
                        || ($record['files'] ?? null) !== $files
                        || ($record['bytes'] ?? null) !== $bytes
                        || ! is_string($record['sha256'] ?? null)
                        || ! hash_equals(hash_final($hash), $record['sha256'])
                        || MediaInventoryIO::readLine($stream) !== null) {
                        throw new RuntimeException('Media inventory integrity check failed.');
                    }
                    return;
                }
                $entry = MediaEntry::decode($line);
                if ($previous !== null && strcmp($previous, $entry->path) >= 0) {
                    throw new RuntimeException('Duplicate or unordered media inventory path.');
                }
                $previous = $entry->path;
                hash_update($hash, $line);
                ++$files;
                $bytes = $this->addBytes($bytes, $entry->size);
                yield $entry;
            }
            throw new RuntimeException('Media inventory is incomplete.');
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    /** Read-only comparison with an independently restored uploads directory. */
    public function verify(MediaSource $restored, string $manifest, string $workParent): MediaVerificationResult
    {
        $parent = $restored->externalDirectory($workParent);
        $expected = $this->entries($manifest);
        $actual = $this->sorter->sorted($restored->entries(), $parent);
        $matched = $missing = $changed = $unexpected = 0;
        try {
            // Consume both streams fully, including the inventory integrity footer.
            while ($expected->valid() || $actual->valid()) {
                $a = $expected->valid() ? $expected->current() : null;
                $b = $actual->valid() ? $actual->current() : null;
                if ($b === null || ($a !== null && strcmp($a->path, $b->path) < 0)) {
                    ++$missing;
                    $expected->next();
                } elseif ($a === null || strcmp($a->path, $b->path) > 0) {
                    ++$unexpected;
                    $actual->next();
                } else {
                    if ($a->size === $b->size && hash_equals($a->sha256, $b->sha256)) {
                        ++$matched;
                    } else {
                        ++$changed;
                    }
                    $expected->next();
                    $actual->next();
                }
            }
            return new MediaVerificationResult($matched, $missing, $changed, $unexpected);
        } finally {
            // Close generator-owned streams and private sort files on exceptions too.
            unset($expected, $actual);
        }
    }

    private function addBytes(int $total, int $size): int
    {
        if ($size > PHP_INT_MAX - $total) {
            throw new RuntimeException('Media inventory byte count overflow.');
        }
        return $total + $size;
    }
}
