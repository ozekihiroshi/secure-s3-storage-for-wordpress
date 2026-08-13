<?php

namespace SecureS3StorageForWordpress\Backup\Retention;

use InvalidArgumentException;

final class RetentionPolicy
{
    public function __construct(
        private int $keepCount
    ) {
        if ($keepCount < 1) {
            throw new InvalidArgumentException(
                'Retention keep count must be at least 1.'
            );
        }
    }

    public function getKeepCount(): int
    {
        return $this->keepCount;
    }
}