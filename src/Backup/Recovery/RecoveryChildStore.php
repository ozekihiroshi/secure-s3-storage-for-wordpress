<?php

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Job\JobStore;

/** Used only under the parent workspace lock, held through child claim and CAS. */
final class RecoveryChildStore implements JobStore
{
    public function __construct(private RecoveryWorkspace $workspace) {}
    public function read(): ?string { return $this->workspace->read('child.json'); }
    public function compareAndSwap(?string $expected, string $replacement): bool
    {
        if ($this->read() !== $expected) { return false; }
        $this->workspace->write('child.json', $replacement);
        return true;
    }
}
