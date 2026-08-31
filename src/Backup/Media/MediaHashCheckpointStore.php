<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Immutable private files; only the job store's CAS selects a published cursor. */
final class MediaHashCheckpointStore
{
    private string $directory;
    private array $identity;
    private const MAX_BYTES = 16384;

    /** Caller supplies an existing private directory and the actual document root. */
    public function __construct(string $directory, MediaSource $source, string $documentRoot)
    {
        // POSIX bits cannot establish Windows ACL privacy. Fail closed for this
        // new feature until an ACL-aware store exists; other backup paths remain unchanged.
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new RuntimeException('Private hash checkpoints require POSIX permissions.');
        }
        $canonical = realpath($directory);
        $web = realpath($documentRoot);
        if ($canonical === false || $web === false || ! is_dir($web)
            || rtrim($directory, '/') !== $canonical) {
            throw new RuntimeException('Canonical private checkpoint directory required.');
        }
        $this->directory = $source->externalDirectory($canonical);
        $web = rtrim($web, '/');
        if ($this->directory === $web || str_starts_with($this->directory, $web . '/')) {
            throw new RuntimeException('Hash checkpoints must be outside the document root.');
        }
        $this->identity = $this->inspectDirectory();
    }

    /** No overwrite/rename/delete: a killed or stale worker leaves an unselected file. */
    public function save(array $data): array
    {
        $this->assertDirectory();
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Hash checkpoint is too large.');
        }
        $id = bin2hex(random_bytes(16));
        $path = $this->directory . '/' . $id . '.json';
        $stream = SecureTemporaryFile::openForWriting($path);
        try {
            MediaInventoryIO::write($stream, $bytes);
            MediaInventoryIO::finish($stream);
            if (! fsync($stream)) {
                throw new RuntimeException('Unable to sync hash checkpoint.');
            }
            $this->assertFile(fstat($stream));
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
        $this->assertDirectory();
        return ['id' => $id, 'sha256' => hash('sha256', $bytes)];
    }

    /** Integrity is checked against the trusted CAS record BEFORE native decoding. */
    public function load(array $reference): array
    {
        $this->assertDirectory();
        if (count($reference) !== 2 || ! is_string($reference['id'] ?? null)
            || ! preg_match('/^[a-f0-9]{32}$/D', $reference['id'])
            || ! is_string($reference['sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $reference['sha256'])) {
            throw new RuntimeException('Invalid hash checkpoint reference.');
        }
        $path = $this->directory . '/' . $reference['id'] . '.json';
        clearstatcache(true, $path);
        $this->assertFile(@lstat($path));
        $stream = MediaInventoryIO::openRead($path);
        try {
            $before = fstat($stream);
            $this->assertFile($before);
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            $after = fstat($stream);
            clearstatcache(true, $path);
            $named = @lstat($path);
            $this->assertFile($named);
            if ($bytes === false || strlen($bytes) !== $before['size']
                || $after === false || $before['size'] !== $after['size']
                || $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime']
                || $named['dev'] !== $before['dev'] || $named['ino'] !== $before['ino']
                || ! hash_equals($reference['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Hash checkpoint changed or is damaged.');
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
        $this->assertDirectory();
        $data = json_decode($bytes, true, 12, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid hash checkpoint.');
        }
        return $data;
    }

    private function assertFile(array|false $stat): void
    {
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1
            || $stat['uid'] !== $this->identity['uid'] || $stat['size'] < 1 || $stat['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Hash checkpoint must be a private regular file.');
        }
    }

    private function inspectDirectory(): array
    {
        // Check ancestors too, including symlinks that resolve to the same target.
        $path = $this->directory;
        do {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Checkpoint directory path changed.');
            }
            $path = dirname($path);
        } while ($path !== '/');
        $stat = lstat($this->directory);
        if (($stat['mode'] & 07777) !== 0700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Checkpoint directory must be owned and private.');
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
    }

    private function assertDirectory(): void
    {
        if ($this->inspectDirectory() !== $this->identity) {
            throw new RuntimeException('Checkpoint directory identity changed.');
        }
    }
}
