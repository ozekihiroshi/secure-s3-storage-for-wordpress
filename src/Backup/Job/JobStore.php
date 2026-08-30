<?php

namespace SecureS3StorageForWordpress\Backup\Job;

interface JobStore
{
    public function read(): ?string;

    /** Replace only the exact observed value; null means insert if absent. */
    public function compareAndSwap(?string $expected, string $replacement): bool;
}
