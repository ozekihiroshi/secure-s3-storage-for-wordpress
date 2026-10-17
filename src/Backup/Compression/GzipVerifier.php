<?php

namespace SecureS3StorageForWordpress\Backup\Compression;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private backup streams need exclusive local creation, bounded reads and direct cleanup, not WP_Filesystem transports.

use RuntimeException;
use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Strict, streaming verification of the single gzip member produced by this plugin. */
final class GzipVerifier
{
    /** @return array{sizeBytes: int, sha256: string} */
    public function verify(string $path, int $maximumBytes, ?string $destination = null): array
    {
        $input = @fopen($path, 'rb');
        if ($input === false || $maximumBytes <= 0) {
            if (is_resource($input)) { fclose($input); }
            throw new RuntimeException('Unable to open gzip for verification.');
        }
        $output = null;
        $created = false;
        $success = false;
        try {
            if ($destination !== null) {
                $output = SecureTemporaryFile::openForWriting($destination);
                $created = true;
            }
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) { throw new RuntimeException('Unable to initialize gzip verification.'); }
            $hash = hash_init('sha256');
            $size = $compressed = 0;
            $ended = false;
            while (! feof($input)) {
                // Small compressed chunks bound worst-case inflated memory consumption.
                $chunk = fread($input, 1024);
                if ($chunk === false) { throw new RuntimeException('Unable to read gzip.'); }
                if ($chunk === '') {
                    if (! feof($input)) { throw new RuntimeException('Gzip read stalled.'); }
                    break;
                }
                if ($ended) { throw new RuntimeException('Trailing gzip data is not accepted.'); }
                $compressed += strlen($chunk);
                $plain = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($plain === false) { throw new RuntimeException('Invalid gzip data or checksum.'); }
                if (strlen($plain) > $maximumBytes - $size) {
                    throw new RuntimeException('Expanded backup exceeds its verification limit.');
                }
                $size += strlen($plain);
                hash_update($hash, $plain);
                if ($output !== null) {
                    CompleteStreamWriter::writeAll($plain, static fn (string $bytes): int|false => fwrite($output, $bytes), 'Unable to write verified SQL.');
                }
                $ended = inflate_get_status($inflate) === ZLIB_STREAM_END;
                if ($ended && inflate_get_read_len($inflate) !== $compressed) {
                    throw new RuntimeException('Trailing gzip data is not accepted.');
                }
            }
            if (! $ended || $size === 0) { throw new RuntimeException('Gzip is incomplete or empty.'); }
            if ($output !== null) {
                if (! fflush($output)) { throw new RuntimeException('Unable to flush verified SQL.'); }
                $closed = fclose($output);
                $output = null;
                if (! $closed) { throw new RuntimeException('Unable to close verified SQL.'); }
            }
            $success = true;
            return ['sizeBytes' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($input);
            if (is_resource($output)) { fclose($output); }
            // Exclusive creation above: never remove a pre-existing destination.
            if (! $success && $created && $destination !== null) { @unlink($destination); }
        }
    }
}
// phpcs:enable WordPress.WP.AlternativeFunctions
