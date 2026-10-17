<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

/** Pure immutable intent/state. Does not perform or persist any backup. */
final class RecoverySet
{
    private function __construct(
        public readonly string $id,
        public readonly string $sourceId,
        public readonly string $profile,
        public readonly string $bucket,
        public readonly string $region,
        public readonly string $prefix,
        public readonly string $createdAt,
        public readonly string $state = 'collecting',
        public readonly ?string $manifestBytes = null,
    ) {
    }

    public static function create(string $sourceId, string $profile, string $bucket, string $region, string $prefix, string $createdAt): self
    {
        return self::intent(bin2hex(random_bytes(16)), $sourceId, $profile, $bucket, $region, $prefix, $createdAt);
    }

    public static function intent(string $id, string $sourceId, string $profile, string $bucket, string $region, string $prefix, string $createdAt): self
    {
        Contract::id($id);
        Contract::id($sourceId);
        Contract::require(in_array($profile, ['database', 'database_uploads'], true));
        Contract::require(preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) === 1);
        Contract::require(preg_match('/^[a-z]{2}(?:-[a-z]+)+-[0-9]+$/D', $region) === 1);
        Contract::path($prefix, true);
        Contract::time($createdAt);
        return new self($id, $sourceId, $profile, $bucket, $region, $prefix, $createdAt);
    }

    public function root(): string
    {
        return ($this->prefix === '' ? '' : $this->prefix . '/') . 'backups/recovery-sets/' . $this->id . '/';
    }

    public function databaseKey(): string
    {
        return $this->root() . 'database/dump.sql.gz';
    }

    public function mediaPrefix(string $jobId): string
    {
        Contract::id($jobId);
        Contract::require($this->profile === 'database_uploads');
        return $this->root() . 'media/' . $jobId . '/';
    }

    public function toIntent(): array
    {
        return ['id' => $this->id, 'sourceId' => $this->sourceId, 'profile' => $this->profile,
            'bucket' => $this->bucket, 'region' => $this->region, 'prefix' => $this->prefix, 'createdAt' => $this->createdAt];
    }

    public static function fromIntent(array $intent): self
    {
        Contract::fields($intent, ['id', 'sourceId', 'profile', 'bucket', 'region', 'prefix', 'createdAt']);
        return self::intent(...$intent);
    }

    public function publish(RecoveryManifest $manifest): self
    {
        Contract::require($this->state === 'collecting');
        $record = $manifest->toArray();
        Contract::require($record['set_id'] === $this->id && $record['source_id'] === $this->sourceId
            && $record['profile'] === $this->profile && $record['created_at'] === $this->createdAt
            && $record['destination']['bucket'] === $this->bucket && $record['destination']['region'] === $this->region
            && $record['destination']['prefix'] === $this->prefix);
        return $this->transition('publishing', $manifest->encode());
    }

    /** Caller supplies independently verified remote receipt, not upload acknowledgement. */
    public function confirm(string $bucket, string $key, int $size, string $sha256): self
    {
        Contract::require($this->state === 'publishing' && $this->manifestBytes !== null);
        Contract::require($bucket === $this->bucket && $key === $this->root() . 'manifest.json'
            && $size === strlen($this->manifestBytes) && hash_equals(hash('sha256', $this->manifestBytes), $sha256));
        return $this->transition('complete', $this->manifestBytes);
    }

    public function fail(): self
    {
        Contract::require(in_array($this->state, ['collecting', 'publishing'], true));
        return $this->transition('failed', $this->manifestBytes);
    }

    private function transition(string $state, ?string $bytes): self
    {
        return new self($this->id, $this->sourceId, $this->profile, $this->bucket, $this->region, $this->prefix, $this->createdAt, $state, $bytes);
    }
}
