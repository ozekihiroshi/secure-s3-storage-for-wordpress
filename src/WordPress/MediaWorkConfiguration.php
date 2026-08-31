<?php

namespace SecureS3StorageForWordpress\WordPress;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Media\MediaHashCheckpointStore;

/** Server-owned configuration, never a path supplied by a browser request. */
final class MediaWorkConfiguration
{
    public const CONSTANT_NAME = 'ODBFS3_MEDIA_WORK_DIR';

    public function directory(): string
    {
        if (! defined(self::CONSTANT_NAME) || ! is_string(constant(self::CONSTANT_NAME))) {
            throw new RuntimeException('Private media storage is not configured.');
        }
        return $this->validate(constant(self::CONSTANT_NAME));
    }

    /** Read-only preflight, also reusable by server-side setup tools. */
    public function validate(string $directory): string
    {
        $source = (new WordPressMediaSourceFactory())->create();
        // Reuse canonical-path, symlink, POSIX 0700, owner and uploads exclusion
        // checks without creating a file or modifying source directories.
        new MediaHashCheckpointStore($directory, $source, ABSPATH);
        if (defined('WP_CONTENT_DIR')) {
            new MediaHashCheckpointStore($directory, $source, WP_CONTENT_DIR);
        }
        // Server configuration, not a slashed form field; preserve the actual
        // filesystem path for the store's canonicalization and exclusion checks.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (is_string($documentRoot) && $documentRoot !== '') {
            new MediaHashCheckpointStore($directory, $source, $documentRoot);
        }
        // Check this PHP process's local POSIX access, not remote WP_Filesystem
        // credentials. Preflight must not prompt for credentials or write files.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        if (! is_readable($directory) || ! is_writable($directory) || ! is_executable($directory)) {
            throw new RuntimeException('Private media storage is not accessible.');
        }
        return rtrim($directory, '/');
    }
}
