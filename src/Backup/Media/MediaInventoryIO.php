<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;

/** Streaming JSON-lines I/O shared by inventory and external-sort files. */
final class MediaInventoryIO
{
    /** @return resource */
    public static function openRead(string $path)
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('Inventory input must be a regular file.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read media inventory.');
        }
        $opened = fstat($stream);
        if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
            throw new RuntimeException('Inventory input changed while opening.');
        }
        return $stream;
    }

    /** @param resource $stream */
    public static function readLine($stream): ?string
    {
        $line = fgets($stream, 65537);
        if ($line === false && feof($stream)) {
            return null;
        }
        if ($line === false || ! str_ends_with($line, "\n")) {
            throw new RuntimeException('Truncated or oversized inventory record.');
        }
        return $line;
    }

    /** @param resource $stream */
    public static function write($stream, string $line): void
    {
        CompleteStreamWriter::writeAll(
            $line,
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            static fn (string $remaining): int|false => fwrite($stream, $remaining),
            'Unable to write media inventory.',
        );
    }

    /** @param resource $stream */
    public static function finish($stream): void
    {
        if (! fflush($stream)) {
            throw new RuntimeException('Unable to flush media inventory.');
        }
    }
}
