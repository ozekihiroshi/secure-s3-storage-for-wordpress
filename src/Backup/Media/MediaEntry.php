<?php

namespace SecureS3StorageForWordpress\Backup\Media;

use RuntimeException;

final class MediaEntry
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly string $sha256,
    ) {
        self::validatePath($path);
        if ($size < 0 || ! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new RuntimeException('Invalid media inventory entry.');
        }
    }

    public static function validatePath(string $path): void
    {
        if (
            $path === '' || str_starts_with($path, '/')
            || strpbrk($path, "\\:\0") !== false
            || preg_match('//u', $path) !== 1
        ) {
            throw new RuntimeException('Unsafe media relative path.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Unsafe media relative path.');
            }
        }
    }

    public function encode(): string
    {
        $line = json_encode(
            ['path' => $this->path, 'size' => $this->size, 'sha256' => $this->sha256],
            JSON_THROW_ON_ERROR,
        ) . "\n";
        // Bound one parser record, not the file size or total inventory size.
        if (strlen($line) > 65536) {
            throw new RuntimeException('Media inventory record is too long.');
        }
        return $line;
    }

    public static function decode(string $line): self
    {
        $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        if (
            ! is_array($data) || count($data) !== 3
            || ! isset($data['path'], $data['size'], $data['sha256'])
            || ! is_string($data['path']) || ! is_int($data['size'])
            || ! is_string($data['sha256'])
        ) {
            throw new RuntimeException('Invalid media inventory entry.');
        }
        return new self($data['path'], $data['size'], $data['sha256']);
    }
}
