<?php

namespace SecureS3StorageForWordpress\Backup\Compression;

use RuntimeException;
use Throwable;

final class GzipCompressor implements Compressor
{
    private const BUFFER_SIZE = 1024 * 1024;

    public function compress(string $sourcePath): CompressedResult
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException(
                'Source file for compression does not exist.'
            );
        }

        $destinationPath = $sourcePath . '.gz';

        $input = null;
        $output = null;
        $success = false;

        try {
            // Source backup data is streamed directly during compression.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $input = fopen($sourcePath, 'rb');

            if ($input === false) {
                throw new RuntimeException(
                    'Unable to open source file for compression.'
                );
            }

            $output = gzopen($destinationPath, 'wb9');

            if ($output === false) {
                throw new RuntimeException(
                    'Unable to create gzip output file.'
                );
            }

            while (! feof($input)) {
                // Backup data is read incrementally to avoid loading the full dump into memory.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $chunk = fread(
                    $input,
                    self::BUFFER_SIZE
                );

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Unable to read source file during compression.'
                    );
                }

                if ($chunk === '') {
                    continue;
                }

                $written = gzwrite(
                    $output,
                    $chunk
                );

                if ($written === false) {
                    throw new RuntimeException(
                        'Unable to write gzip output file.'
                    );
                }
            }

            // Direct stream handling is required by the compressor.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($input);
            $input = null;

            gzclose($output);
            $output = null;

            if (! is_file($destinationPath)) {
                throw new RuntimeException(
                    'Compressed file was not created.'
                );
            }

            $size = filesize($destinationPath);

            if ($size === false || $size <= 0) {
                throw new RuntimeException(
                    'Compressed file is empty.'
                );
            }

            $success = true;

            return new CompressedResult(
                path: $destinationPath,
                sizeBytes: $size,
                algorithm: 'gzip'
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'File compression failed.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );
        } finally {
            if (is_resource($input)) {
                // Direct stream handling is required during cleanup.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($input);
            }

            if (is_resource($output)) {
                gzclose($output);
            }

            if (
                ! $success
                && is_file($destinationPath)
            ) {
                // Temporary compressed artifact is managed directly by the backup engine.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($destinationPath);
            }
        }
    }
}
