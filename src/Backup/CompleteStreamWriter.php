<?php

namespace SecureS3StorageForWordpress\Backup;

use RuntimeException;

final class CompleteStreamWriter
{
    /**
     * @param callable(string): (int|false) $writeChunk
     */
    public static function writeAll(
        string $content,
        callable $writeChunk,
        string $failureMessage
    ): void {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $remaining = substr($content, $offset);
            $written = $writeChunk($remaining);

            if (
                ! is_int($written)
                || $written <= 0
                || $written > strlen($remaining)
            ) {
                throw new RuntimeException(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message is not HTML output.
                    $failureMessage
                );
            }

            $offset += $written;
        }
    }
}
