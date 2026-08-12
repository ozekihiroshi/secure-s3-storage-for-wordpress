<?php

namespace SecureS3StorageForWordpress\Aws;

final class S3UploadResult
{
    public function __construct(
        private string $bucket,
        private string $key,
        private int $sizeBytes,
        private ?string $etag = null,
    ) {
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }
}