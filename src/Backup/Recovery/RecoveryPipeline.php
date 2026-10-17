<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

use SecureS3StorageForWordpress\Backup\Database\DatabaseDumper;
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Compression\GzipVerifier;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;
use SecureS3StorageForWordpress\Backup\Media\MediaObjectClient;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaPreparationStep;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;

/** Real DB/media adapters. Caller schedules collector ticks; no production hooks. */
final class RecoveryPipeline implements RecoveryStep, RecoveryLock
{
    public function __construct(
        private RecoveryWorkspace $workspace,
        private DatabaseDumper $dumper,
        private DatabaseConnection $connection,
        private string $backend,
        private MediaObjectClient $client,
        private MediaSource $uploads,
        private string $documentRoot,
    ) {
        Contract::require(in_array($backend, ['native', 'php'], true));
    }

    public function acquire(): bool { return $this->workspace->acquire(); }
    public function release(): void { $this->workspace->release(); }

    public function execute(RecoverySet $set, string $phase, array $checkpoint, int $deadline): RecoveryProgress
    {
        Contract::require($set->toIntent() === $this->workspace->set->toIntent());
        if (time() >= $deadline - 3) { throw new RetryableJobException('Fresh recovery lease required.'); }
        return match ($phase) {
            'database' => $this->database($set, $checkpoint, $deadline),
            'media' => $this->media($set, $checkpoint, $deadline),
            default => throw new \RuntimeException('Unexpected recovery phase.'),
        };
    }

    private function database(RecoverySet $set, array $checkpoint, int $deadline): RecoveryProgress
    {
        $saved = $this->workspace->read('database.json');
        if ($saved === null) {
            // No remote operation can precede durable prepared evidence.
            $this->workspace->resetUncommittedDatabase();
            $start = gmdate('Y-m-d\TH:i:s\Z');
            $dump = $this->dumper->dump($this->connection);
            $input = null; $output = null;
            try {
                $input = fopen($dump->getPath(), 'rb');
                $output = SecureTemporaryFile::openForWriting($this->workspace->path('database.sql'));
                Contract::require(is_resource($input) && stream_copy_to_stream($input, $output) > 0);
                Contract::require(fflush($output) && fsync($output));
            } finally {
                if (is_resource($input)) { fclose($input); }
                if (is_resource($output)) { fclose($output); }
                // Exact temporary file returned by the trusted dumper, never a user path.
                unlink($dump->getPath());
            }
            $end = gmdate('Y-m-d\TH:i:s\Z');
            $sql = $this->workspace->path('database.sql');
            $raw = ['size_bytes' => filesize($sql), 'sha256' => hash_file('sha256', $sql)];
            $gzip = (new GzipCompressor())->compress($sql)->getPath();
            $verified = (new GzipVerifier())->verify($gzip, $raw['size_bytes']);
            Contract::require($verified['sizeBytes'] === $raw['size_bytes'] && $verified['sha256'] === $raw['sha256']);
            $stream = $this->workspace->open('database.sql.gz');
            Contract::require(fsync($stream)); fclose($stream);
            $prepared = ['capture' => ['start' => $start, 'end' => $end], 'raw_sql' => $raw, 'backend' => $this->backend,
                'artifact' => ['key' => $set->databaseKey(), 'size_bytes' => filesize($gzip), 'sha256' => hash_file('sha256', $gzip), 'version_id' => null]];
            $this->workspace->write('database.json', json_encode($prepared, JSON_THROW_ON_ERROR));
            return new RecoveryProgress(['prepared' => $prepared['artifact']['sha256']]);
        }
        $prepared = json_decode($saved, true, 16, JSON_THROW_ON_ERROR);
        $artifact = $prepared['artifact'];
        Contract::require($artifact['key'] === $set->databaseKey());
        if (isset($checkpoint['prepared'])) { Contract::require($checkpoint['prepared'] === $artifact['sha256']); }
        $stream = $this->workspace->open('database.sql.gz');
        try {
            $hash = hash_init('sha256'); hash_update_stream($hash, $stream);
            Contract::require(fstat($stream)['size'] === $artifact['size_bytes'] && hash_final($hash) === $artifact['sha256']);
            rewind($stream);
            $transfer = $this->workspace->read('transfer.json');
            if ($transfer === null) {
                $transfer = json_encode(['start' => gmdate('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR);
                $this->workspace->write('transfer.json', $transfer);
            }
            $start = json_decode($transfer, true, 4, JSON_THROW_ON_ERROR)['start'];
            $key = ['Bucket' => $set->bucket, 'Key' => $set->databaseKey()];
            $head = $this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline);
            $expected = base64_encode(hex2bin($artifact['sha256']));
            if ($head['missing'] ?? false) {
                $this->client->request('PutObject', $key + ['Body' => $stream, 'RangeOffset' => 0,
                    'ContentLength' => $artifact['size_bytes'], 'ContentType' => 'application/gzip',
                    'ChecksumSHA256' => $expected, 'IfNoneMatch' => '*'], $deadline);
                $head = $this->client->request('HeadObject', $key + ['ChecksumMode' => 'ENABLED'], $deadline);
            }
            Contract::require(($head['ContentLength'] ?? null) === $artifact['size_bytes'] && ($head['ChecksumSHA256'] ?? null) === $expected);
        } finally { fclose($stream); }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $evidence = ['set_id' => $set->id, 'source_id' => $set->sourceId, 'capture' => $prepared['capture'],
            'transfer' => ['start' => $start, 'end' => $now], 'verified_at' => $now, 'artifact' => $artifact,
            'raw_sql' => $prepared['raw_sql'], 'backend' => $prepared['backend'], 'method' => 'sql_gzip_s3_sha256'];
        return new RecoveryProgress([], $evidence);
    }

    private function media(RecoverySet $set, array $checkpoint, int $deadline): RecoveryProgress
    {
        Contract::require($set->profile === 'database_uploads');
        $store = new RecoveryChildStore($this->workspace);
        $metadata = $this->workspace->read('media.json');
        if ($metadata === null) {
            $start = gmdate('Y-m-d\TH:i:s\Z');
            $initial = MediaPreparationStep::initialize($this->uploads, $this->workspace->directory, $this->documentRoot);
            $initial += ['bucket' => $set->bucket, 'region' => $set->region, 'prefix' => $set->prefix === '' ? '' : $set->prefix . '/'];
            $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $initial);
            $metadata = json_encode(['job_id' => $job->id, 'capture_start' => $start,
                'source_root' => $this->uploads->rootPath(), 'initial' => $job->encode()], JSON_THROW_ON_ERROR);
            // Persist binding BEFORE child initialization or any remote work.
            $this->workspace->write('media.json', $metadata);
        }
        $meta = json_decode($metadata, true, 16, JSON_THROW_ON_ERROR);
        Contract::require($meta['source_root'] === $this->uploads->rootPath());
        if (isset($checkpoint['job_id'])) { Contract::require($checkpoint['job_id'] === $meta['job_id']); }
        if ($store->read() === null) { Contract::require($store->compareAndSwap(null, $meta['initial'])); }
        $job = BackupJob::decode($store->read());
        Contract::require($job->id === $meta['job_id'] && $job->type === 'media');
        if ($job->status === 'failed') { throw new \RuntimeException('Recovery media child failed.'); }
        if (isset($job->checkpoint['phase'])) {
            $status = (new MediaPreparationStep($this->uploads, $job->checkpoint['directory'], $this->documentRoot))->tick($store, $job->id);
        } else {
            if (! isset($meta['capture_end'])) {
                $meta['capture_end'] = gmdate('Y-m-d\TH:i:s\Z');
                $this->workspace->write('media.json', json_encode($meta, JSON_THROW_ON_ERROR));
            }
            // Child deadline may not outlive the parent's lease.
            $remaining = $deadline - time() - 2;
            if ($remaining < 3) { throw new RetryableJobException('Fresh child lease required.'); }
            $status = (new JobRunner($store, leaseSeconds: $remaining))->tick($job->id, 'media', new MediaUploadStep($this->client, $set));
        }
        if ($status === 'failed') { throw new \RuntimeException('Recovery media child failed.'); }
        $job = BackupJob::decode($store->read());
        if ($job->status !== 'succeeded') {
            $next = ['job_id' => $job->id, 'child_sha256' => hash('sha256', $store->read())];
            if ($next === $checkpoint) { throw new RecoveryPending('Recovery media child is busy.'); }
            return new RecoveryProgress($next);
        }
        $plan = new MediaUploadPlan($job->checkpoint['directory']);
        Contract::require($plan->metadata() === $job->checkpoint['metadata']);
        $body = json_encode(['format' => 'odbfs3-media-complete', 'version' => 1, 'run' => $job->id, 'inventory' => 'inventory.jsonl',
            'inventory_sha256' => $plan->metadata()['inventory_sha256'], 'files' => $job->processedFiles, 'bytes' => $job->processedBytes,
            'object_key_rule' => 'files/sha256(UTF-8 relative path)'], JSON_THROW_ON_ERROR) . "\n";
        $prefix = $set->mediaPrefix($job->id);
        $inventory = $plan->open('inventory.jsonl');
        try { $size = fstat($inventory)['size']; } finally { fclose($inventory); }
        $artifacts = [
            'inventory' => ['key' => $prefix . 'inventory.jsonl', 'size_bytes' => $size, 'sha256' => $plan->metadata()['inventory_sha256'], 'version_id' => null],
            'marker' => ['key' => $prefix . 'complete.json', 'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'version_id' => null],
        ];
        foreach ($artifacts as $name => $a) {
            $head = $this->client->request('HeadObject', ['Bucket' => $set->bucket, 'Key' => $a['key'], 'ChecksumMode' => 'ENABLED'], $deadline);
            $checksum = $name === 'inventory' ? ($job->checkpoint['inventory_s3_checksum'] ?? null) : base64_encode(hex2bin($a['sha256']));
            Contract::require(is_string($checksum) && ($head['ContentLength'] ?? null) === $a['size_bytes'] && ($head['ChecksumSHA256'] ?? null) === $checksum);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new RecoveryProgress([], ['set_id' => $set->id, 'source_id' => $set->sourceId,
            'capture' => ['start' => $meta['capture_start'], 'end' => $meta['capture_end']],
            'transfer' => ['start' => $meta['capture_end'], 'end' => $now], 'verified_at' => $now,
            'job_id' => $job->id, 'prefix' => $prefix] + $artifacts + ['file_count' => $job->processedFiles, 'total_bytes' => $job->processedBytes,
                'marker_evidence' => json_decode($body, true, 8, JSON_THROW_ON_ERROR),
                'method' => 'inventory_full_sha256_objects_full_or_composite_sha256']);
    }
}
