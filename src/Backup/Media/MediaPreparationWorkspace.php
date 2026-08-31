<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** Scratch-file mutation is allowed only while the entire JobRunner tick is locked. */
final class MediaPreparationWorkspace
{
    private $lock = null;
    public readonly MediaHashCheckpointStore $checkpoints;

    public function __construct(public readonly string $directory, MediaSource $source, string $documentRoot)
    {
        $this->checkpoints = new MediaHashCheckpointStore($directory, $source, $documentRoot);
    }

    public function identity(): array
    {
        clearstatcache(true, $this->directory);
        $stat = lstat($this->directory);
        return [$stat['dev'], $stat['ino'], $stat['mode'], $stat['uid']];
    }

    public function acquire(): bool
    {
        if ($this->lock !== null) { throw new RuntimeException('Workspace already locked.'); }
        $stream = $this->read('worker.lock');
        if (! flock($stream, LOCK_EX | LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
            return false;
        }
        $this->lock = $stream;
        return true;
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function locked(): bool { return $this->lock !== null; }

    /** @return resource */
    public function read(string $name)
    {
        $path = $this->path($name);
        clearstatcache(true, $path);
        $this->assertFile(@lstat($path));
        $stream = MediaInventoryIO::openRead($path);
        try { $this->assertFile(fstat($stream)); } catch (\Throwable $e) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
            throw $e;
        }
        return $stream;
    }

    /** Discard only uncommitted tail bytes, never the committed prefix. @return resource */
    public function output(string $name, int $offset)
    {
        if (! $this->locked() || $offset < 0) { throw new RuntimeException('Unlocked preparation write.'); }
        $path = $this->path($name);
        clearstatcache(true, $path);
        if (lstat($this->directory) === false) { throw new RuntimeException('Missing workspace.'); }
        if (@lstat($path) === false) {
            if ($offset !== 0) { throw new RuntimeException('Missing committed preparation file.'); }
            return SecureTemporaryFile::openForWriting($path);
        }
        $this->assertFile(lstat($path));
        // An owned, private regular scratch file; not a path from a request.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = fopen($path, 'r+b');
        if ($stream === false) { throw new RuntimeException('Cannot open preparation output.'); }
        try {
            $stat = fstat($stream);
            $this->assertFile($stat);
            clearstatcache(true, $path);
            $named = @lstat($path);
            $this->assertFile($named);
            if ($named['dev'] !== $stat['dev'] || $named['ino'] !== $stat['ino']
                || $stat['size'] < $offset || ! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Preparation output prefix is unavailable.');
            }
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
            throw $e;
        }
        return $stream;
    }

    /** @param resource $stream */
    public static function finish($stream): int
    {
        try {
            MediaInventoryIO::finish($stream);
            if (! fsync($stream)) { throw new RuntimeException('Cannot sync preparation output.'); }
            return ftell($stream);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    /** Read a bounded record only within the committed extent. */
    public function line(string $name, int &$offset, int $size): ?string
    {
        if ($offset === $size) { return null; }
        $stream = $this->read($name);
        try {
            if ($offset < 0 || $offset > $size || fstat($stream)['size'] < $size || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Invalid preparation read cursor.');
            }
            $line = MediaInventoryIO::readLine($stream);
            $offset = ftell($stream);
            if ($line === null || $offset > $size) { throw new RuntimeException('Truncated preparation record.'); }
            return $line;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }

    public static function encode(array $record): string
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($line) > 65536) { throw new RuntimeException('Preparation record is too long.'); }
        return $line;
    }

    private function path(string $name): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{0,100}$/D', $name)) {
            throw new RuntimeException('Invalid preparation file identifier.');
        }
        return $this->directory . '/' . $name;
    }

    private function assertFile(array|false $stat): void
    {
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1
            || $stat['uid'] !== $this->identity()[3]) {
            throw new RuntimeException('Preparation file is not private and regular.');
        }
    }
}
