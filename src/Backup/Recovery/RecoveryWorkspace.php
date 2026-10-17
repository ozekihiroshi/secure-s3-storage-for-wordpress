<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

/** POSIX private persistent storage. No automatic deletion or recursive cleanup. */
final class RecoveryWorkspace implements RecoveryLock
{
    private $lock = null;
    private array $identity;
    public readonly string $directory;

    public function __construct(string $parent, public readonly RecoverySet $set, string $webRoot, string $uploadsRoot)
    {
        Contract::require(DIRECTORY_SEPARATOR === '/' && realpath($parent) === $parent);
        $stat = lstat($parent);
        self::privateDirectory($stat);
        foreach ([$webRoot, $uploadsRoot] as $excluded) {
            $root = realpath($excluded);
            Contract::require($root !== false && $parent !== $root && ! str_starts_with($parent, rtrim($root, '/') . '/'));
        }
        $this->directory = $parent . '/recovery-' . $set->id;
        if (@mkdir($this->directory, 0700)) {
            $stream = SecureTemporaryFile::openForWriting($this->directory . '/worker.lock');
            fclose($stream);
        }
        $this->identity = $this->inspect();
        // An initialization crash before worker.lock creation fails closed.
        $stream = $this->open('worker.lock'); fclose($stream);
    }

    public function acquire(): bool
    {
        Contract::require($this->lock === null);
        $stream = $this->open('worker.lock');
        if (! flock($stream, LOCK_EX | LOCK_NB)) { fclose($stream); return false; }
        $this->lock = $stream;
        try {
            $intent = $this->read('intent.json');
            $expected = json_encode($this->set->toIntent(), JSON_THROW_ON_ERROR);
            if ($intent === null) { $this->write('intent.json', $expected); }
            else { Contract::require($intent === $expected); }
        } catch (\Throwable $e) { $this->release(); throw $e; }
        return true;
    }

    public function release(): void
    {
        if ($this->lock !== null) { flock($this->lock, LOCK_UN); fclose($this->lock); $this->lock = null; }
    }

    public function __destruct() { $this->release(); }

    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if (@lstat($path) === false) { return null; }
        $stream = $this->open($name);
        try {
            $data = stream_get_contents($stream, 262145);
            Contract::require(is_string($data) && strlen($data) <= 262144 && feof($stream));
            return $data;
        } finally { fclose($stream); }
    }

    public function write(string $name, string $bytes): void
    {
        Contract::require($this->lock !== null && strlen($bytes) <= 262144 && $name !== 'worker.lock');
        $target = $this->path($name);
        if (@lstat($target) !== false) { $s = $this->open($name); fclose($s); }
        $temporary = $this->directory . '/pending-' . bin2hex(random_bytes(16));
        $stream = SecureTemporaryFile::openForWriting($temporary);
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $n = fwrite($stream, substr($bytes, $offset));
                Contract::require(is_int($n) && $n > 0); $offset += $n;
            }
            Contract::require(fflush($stream) && fsync($stream));
        } finally { fclose($stream); }
        $this->path($name);
        Contract::require(rename($temporary, $target));
    }

    public function path(string $name): string
    {
        Contract::require(in_array($name, ['worker.lock', 'intent.json', 'database.json', 'database.sql', 'database.sql.gz', 'media.json', 'child.json', 'transfer.json'], true));
        Contract::require($this->inspect() === $this->identity);
        return $this->directory . '/' . $name;
    }

    public function open(string $name)
    {
        $path = $this->path($name);
        clearstatcache(true, $path);
        $before = @lstat($path); self::privateFile($before);
        $stream = fopen($path, 'rb');
        Contract::require(is_resource($stream));
        try {
            $after = fstat($stream); self::privateFile($after);
            Contract::require($before['dev'] === $after['dev'] && $before['ino'] === $after['ino']);
            clearstatcache(true, $path);
            $named = @lstat($path); self::privateFile($named);
            Contract::require($named['dev'] === $after['dev'] && $named['ino'] === $after['ino']);
        } catch (\Throwable $e) { fclose($stream); throw $e; }
        return $stream;
    }

    /** Only uncommitted, locally prepared DB files; never remote objects. */
    public function resetUncommittedDatabase(): void
    {
        Contract::require($this->lock !== null && $this->read('database.json') === null && $this->read('transfer.json') === null);
        foreach (['database.sql', 'database.sql.gz'] as $name) {
            $path = $this->path($name);
            if (@lstat($path) !== false) {
                $stream = $this->open($name); fclose($stream);
                Contract::require(unlink($path));
            }
        }
    }

    private function inspect(): array
    {
        clearstatcache(true, $this->directory);
        $s = @lstat($this->directory); self::privateDirectory($s);
        return [$s['dev'], $s['ino'], $s['mode'], $s['uid']];
    }

    private static function privateDirectory(array|false $s): void
    {
        Contract::require($s !== false && ($s['mode'] & 0170000) === 0040000 && ($s['mode'] & 0777) === 0700);
        Contract::require(function_exists('posix_geteuid') && $s['uid'] === posix_geteuid());
    }

    private static function privateFile(array|false $s): void
    {
        Contract::require($s !== false && ($s['mode'] & 0170000) === 0100000 && ($s['mode'] & 0777) === 0600
            && $s['nlink'] === 1 && $s['uid'] === posix_geteuid());
    }
}
