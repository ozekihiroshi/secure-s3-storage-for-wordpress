<?php

namespace SecureS3StorageForWordpress\Backup\Job;

use InvalidArgumentException;

final class StepResult
{
    /** @param array<string, mixed> $checkpoint Small cursors only; never credentials. */
    public function __construct(
        public readonly array $checkpoint,
        public readonly int $processedFiles,
        public readonly int $processedBytes,
        public readonly bool $complete = false,
    ) {
        if ($processedFiles < 0 || $processedBytes < 0) {
            throw new InvalidArgumentException('Invalid backup progress.');
        }
    }
}
