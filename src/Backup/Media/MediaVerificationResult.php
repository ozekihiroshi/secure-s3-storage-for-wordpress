<?php

namespace SecureS3StorageForWordpress\Backup\Media;

final class MediaVerificationResult
{
    public function __construct(
        public readonly int $matched,
        public readonly int $missing,
        public readonly int $changed,
        public readonly int $unexpected,
    ) {
    }

    public function successful(): bool
    {
        return $this->missing === 0 && $this->changed === 0 && $this->unexpected === 0;
    }
}
