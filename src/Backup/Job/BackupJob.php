<?php

namespace SecureS3StorageForWordpress\Backup\Job;

use RuntimeException;

final class BackupJob
{
    private const MAX_RECORD_BYTES = 32768;

    /** @param array<string, mixed> $checkpoint Internal cursors, not file contents or secrets. */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $status = 'queued',
        public readonly array $checkpoint = [],
        public readonly int $processedFiles = 0,
        public readonly int $processedBytes = 0,
        public readonly string $leaseToken = '',
        public readonly int $leaseUntil = 0,
        public readonly int $attempts = 0,
        public readonly string $errorCode = '',
    ) {
        if (
            ! preg_match('/^[a-f0-9]{32}$/D', $id)
            || ! in_array($type, ['media', 'database'], true)
            || ! in_array($status, ['queued', 'running', 'succeeded', 'failed'], true)
            || $processedFiles < 0 || $processedBytes < 0 || $attempts < 0
            || $leaseUntil < 0
            || ($leaseToken !== '' && ! preg_match('/^[a-f0-9]{32}$/D', $leaseToken))
            || (($leaseToken === '') !== ($leaseUntil === 0))
            || ($leaseToken !== '' && $status !== 'running')
            || ! in_array($errorCode, ['', 'step_failed', 'recovery_exhausted', 'preparation_requires_cli'], true)
            || (($status === 'failed') !== ($errorCode !== ''))
        ) {
            throw new RuntimeException('Invalid backup job state.');
        }
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function claim(int $now, int $leaseSeconds): self
    {
        if ($this->terminal() || $now < 1 || $leaseSeconds < 1 || $this->leaseUntil > $now) {
            throw new RuntimeException('Backup job cannot be claimed.');
        }

        return new self(
            $this->id, $this->type, 'running', $this->checkpoint,
            $this->processedFiles, $this->processedBytes,
            bin2hex(random_bytes(16)), $now + $leaseSeconds, $this->attempts + 1,
        );
    }

    public function advance(StepResult $result): self
    {
        if (
            $this->leaseToken === '' || $this->status !== 'running'
            || $result->processedFiles < $this->processedFiles
            || $result->processedBytes < $this->processedBytes
            || (! $result->complete
                && $result->checkpoint === $this->checkpoint
                && $result->processedFiles === $this->processedFiles
                && $result->processedBytes === $this->processedBytes)
        ) {
            throw new RuntimeException('Invalid backup checkpoint transition.');
        }

        return new self(
            $this->id, $this->type, $result->complete ? 'succeeded' : 'running',
            $result->checkpoint, $result->processedFiles, $result->processedBytes,
        );
    }

    public function fail(string $errorCode): self
    {
        if ($this->terminal()) {
            throw new RuntimeException('Backup job is already terminal.');
        }

        return new self(
            $this->id, $this->type, 'failed', $this->checkpoint,
            $this->processedFiles, $this->processedBytes,
            errorCode: $errorCode,
        );
    }

    public function encode(): string
    {
        $json = json_encode(['schema' => 1, 'job' => get_object_vars($this)], JSON_THROW_ON_ERROR, 32);
        if (strlen($json) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('Backup checkpoint is too large.');
        }

        return $json;
    }

    public static function decode(string $json): self
    {
        if (strlen($json) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('Backup checkpoint is too large.');
        }

        $record = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $job = $record['job'] ?? null;
        if (! is_array($record) || ($record['schema'] ?? null) !== 1 || ! is_array($job)) {
            throw new RuntimeException('Invalid backup job record.');
        }

        $types = [
            'id' => 'string', 'type' => 'string', 'status' => 'string',
            'checkpoint' => 'array', 'processedFiles' => 'integer',
            'processedBytes' => 'integer', 'leaseToken' => 'string',
            'leaseUntil' => 'integer', 'attempts' => 'integer', 'errorCode' => 'string',
        ];
        if (count($job) !== count($types)) {
            throw new RuntimeException('Invalid backup job record.');
        }
        foreach ($types as $key => $type) {
            if (! array_key_exists($key, $job) || gettype($job[$key]) !== $type) {
                throw new RuntimeException('Invalid backup job record.');
            }
        }

        return new self(...$job);
    }
}
