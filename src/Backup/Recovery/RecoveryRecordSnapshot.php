<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Job\JobStore;
use LogicException;

/** A single captured record shared by projection and manifest validation. */
final class RecoveryRecordSnapshot implements JobStore
{
    public function __construct(private ?string $bytes) {}
    public function read(): ?string { return $this->bytes; }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        throw new LogicException('Read-only recovery snapshot.');
    }
}
