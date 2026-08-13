<?php

namespace SecureS3StorageForWordpress\Backup\Retention;

final class RetentionDeleteResult
{
    /**
     * @param list<RetentionCandidate> $deletedCandidates
     */
    public function __construct(
        private array $deletedCandidates
    ) {
    }

    /**
     * @return list<RetentionCandidate>
     */
    public function getDeletedCandidates(): array
    {
        return $this->deletedCandidates;
    }

    public function getDeletedCount(): int
    {
        return count(
            $this->deletedCandidates
        );
    }

    public function getDeletedBytes(): int
    {
        $total = 0;

        foreach (
            $this->deletedCandidates as $candidate
        ) {
            $total +=
                $candidate->getSizeBytes();
        }

        return $total;
    }
}