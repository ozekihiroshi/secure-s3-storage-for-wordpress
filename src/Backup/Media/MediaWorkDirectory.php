<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Own only generated sort files, never source media or pre-existing files. */
final class MediaWorkDirectory
{
    private string $directory;
    private array $owned = [];

    public function __construct(string $parent)
    {
        $this->directory = rtrim($parent, '/\\') . '/odbfs3-inventory-' . bin2hex(random_bytes(16));
        // Private directory before any inventory data is written.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Unable to create media work directory.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($this->directory) & 0777) !== 0700) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir($this->directory);
            throw new RuntimeException('Media work directory is not private.');
        }
    }

    /** @return array{0: string, 1: resource} */
    public function createFile(): array
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(16)) . '.jsonl';
        $stream = SecureTemporaryFile::openForWriting($path);
        $this->owned[$path] = true;
        return [$path, $stream];
    }

    public function remove(string $path): void
    {
        if (! isset($this->owned[$path])) {
            throw new RuntimeException('Unowned media work file.');
        }
        // Only files exclusively created in this private work directory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        if (! unlink($path)) {
            throw new RuntimeException('Unable to clean media work file.');
        }
        unset($this->owned[$path]);
    }

    public function close(): void
    {
        foreach (array_keys($this->owned) as $path) {
            $this->remove($path);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (! rmdir($this->directory)) {
            throw new RuntimeException('Unable to clean media work directory.');
        }
    }
}
