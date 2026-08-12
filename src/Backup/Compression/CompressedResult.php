<?php

namespace SecureS3StorageForWordpress\Backup\Compression;

final class CompressedResult
{
    public function __construct(
        private string $path,
        private int $sizeBytes,
        private string $algorithm,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }
}