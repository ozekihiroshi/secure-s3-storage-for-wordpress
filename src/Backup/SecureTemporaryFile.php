<?php

namespace SecureS3StorageForWordpress\Backup;

use RuntimeException;
use Throwable;

final class SecureTemporaryFile
{
    public static function create(string $path): void
    {
        $handle = self::openForWriting($path);

        // The secured placeholder is closed before another writer opens it.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
    }

    /**
     * @return resource
     */
    public static function openForWriting(string $path)
    {
        if ($path === '') {
            throw new RuntimeException(
                'Temporary backup file path is required.'
            );
        }

        // Exclusive creation prevents an existing file from being overwritten.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create temporary backup file.'
            );
        }

        try {
            // Permissions are restricted before any backup data is written.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            if (! chmod($path, 0600)) {
                throw new RuntimeException(
                    'Unable to restrict temporary backup file permissions.'
                );
            }

            self::assertPrivatePermissions($path);
        } catch (Throwable $e) {
            // Close and remove an unsecured empty placeholder immediately.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($path);

            throw new RuntimeException(
                'Unable to secure temporary backup file.',
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is chained, not output.
                $e
            );
        }

        return $handle;
    }

    private static function assertPrivatePermissions(
        string $path
    ): void {
        /*
         * Windows does not expose POSIX mode bits consistently. chmod() is
         * still attempted above, while Linux and Unix hosts are verified.
         */
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        clearstatcache(true, $path);

        $permissions = fileperms($path);

        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new RuntimeException(
                'Temporary backup file permissions are not private.'
            );
        }
    }
}
