<?php

namespace SecureS3StorageForWordpress\Backup\Job;

interface JobStep
{
    /**
     * Work within the deadline; return cumulative progress and a small cursor.
     * Effects must be idempotent and isolated by job ID, even after lease loss.
     * Return complete only after verifying the entire backup's final manifest.
     */
    public function execute(BackupJob $job, int $deadline): StepResult;
}
