<?php

namespace SecureS3StorageForWordpress\WordPress;

use Closure;
use SecureS3StorageForWordpress\Backup\Job\JobStore;
use RuntimeException;
use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Media\MediaFailedJobCleanup;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;
use SecureS3StorageForWordpress\Backup\Media\MediaPreparationStep;
use Throwable;

/** Explicit submission only; no media backup starts merely by loading the plugin. */
final class MediaJobController
{
    public const HOOK = 'secure_s3_storage_media_worker';

    private const MAX_BATCH_STEPS = 1000;
    private const MAX_UPLOAD_STEPS = 250;
    private const NEXT_BATCH_DELAY = 5;
    private const RECOVERY_DELAY = 60;

    public function __construct(private ?JobStore $jobStore = null, private ?Closure $clientFactory = null)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run'], 10, 1);
        add_action('init', [$this, 'recoverSchedule']);
    }

    public function current(): ?BackupJob
    {
        $record = $this->store()->read();
        return $record === null ? null : BackupJob::decode($record);
    }

    public function start(MediaUploadPlan $plan): BackupJob
    {
        $metadata = $plan->metadata();
        self::assertOutsideWebRoot($plan->directory);
        $source = (new WordPressMediaSourceFactory())->create();
        if ($source->rootPath() !== $metadata['root']) {
            throw new RuntimeException('Media plan belongs to another upload root.');
        }
        $identity = MediaFailedJobCleanup::captureIdentity(
            $plan->directory,
            [ABSPATH, $source->rootPath()],
        );
        return $this->submit(['directory' => $plan->directory, 'metadata' => $metadata,
            'offset' => 0, 'work_identity' => $identity]);
    }

    /** Queue preparation without scanning uploads in the submitting request. */
    public function enqueuePreparation(string $parent, ?string $expectedPreviousId = null): BackupJob
    {
        $old = $this->current();
        if ($expectedPreviousId !== null && ($old?->id ?? '') !== $expectedPreviousId) {
            throw new RuntimeException('The media start form is out of date.');
        }
        if ($old !== null && (! $old->terminal() || $old->type !== 'media')) {
            throw new RuntimeException('A backup job is already active.');
        }
        if (($old?->checkpoint['cleanup']['state'] ?? null) === 'pending') {
            throw new RuntimeException('Failed media cleanup must finish before a new job starts.');
        }
        self::assertOutsideWebRoot($parent);
        $source = (new WordPressMediaSourceFactory())->create();
        $state = MediaPreparationStep::initialize($source, $parent, ABSPATH);
        $state['work_identity'] = MediaFailedJobCleanup::captureIdentity(
            $state['directory'],
            [ABSPATH, $source->rootPath()],
        );
        return $this->submit($state, $expectedPreviousId);
    }

    private function submit(array $initial, ?string $expectedPreviousId = null): BackupJob
    {
        $options = get_option('secure_s3_storage_settings', []);
        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';
        $prefix = $options['prefix'] ?? '';
        if (! is_string($region) || $region === '' || ! is_string($bucket) || $bucket === ''
            || ! is_string($prefix) || strlen($prefix) + 128 > 1024 || str_contains($prefix, "\0")) {
            throw new RuntimeException('Valid AWS destination settings are required.');
        }
        $prefix = trim($prefix, '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $store = $this->store();
        $observed = $store->read();
        // Recheck after preparation initialization, immediately before the CAS.
        // Even a completed intervening job invalidates an earlier browser form.
        if ($expectedPreviousId !== null) {
            $observedId = $observed === null ? '' : BackupJob::decode($observed)->id;
            if ($observedId !== $expectedPreviousId) {
                throw new RuntimeException('The media start form is out of date.');
            }
        }
        if ($observed !== null) {
            $old = BackupJob::decode($observed);
            if (! $old->terminal() || $old->type !== 'media') {
                throw new RuntimeException('A backup job is already active.');
            }
            if (($old->checkpoint['cleanup']['state'] ?? null) === 'pending') {
                throw new RuntimeException('Failed media cleanup must finish before a new job starts.');
            }
            // Archive before replacing the slot. Concurrent starters write identical history.
            $name = 'secure_s3_storage_media_result_' . $old->id;
            if (! add_option($name, $observed, '', false) && get_option($name) !== $observed) {
                throw new RuntimeException('Unable to archive the previous backup result.');
            }
        }
        $job = new BackupJob(bin2hex(random_bytes(16)), 'media', checkpoint: $initial + [
            'region' => $region, 'bucket' => $bucket, 'prefix' => $prefix,
        ]);
        if (! $store->compareAndSwap($observed, $job->encode())) {
            throw new RuntimeException('Another backup submission won the slot.');
        }
        // If scheduling fails the durable queued job remains recoverable by CLI/init.
        $this->schedule($job->id);
        return $job;
    }

    public function recoverSchedule(): void
    {
        try {
            $job = $this->current();
            if ($job !== null && $job->type === 'media' && ! $job->terminal()) {
                $this->schedule($job->id);
            }
        } catch (Throwable $e) {
            // Do not break page requests or expose DB/SDK details. CLI reports safe status.
        }
    }

    public function run(string $id): string
    {
        try {
            $job = $this->current();
            if ($job === null || $job->id !== $id || $job->type !== 'media') {
                wp_clear_scheduled_hook(self::HOOK, [$id]);
                return 'mismatch';
            }
            if ($job->terminal()) {
                wp_clear_scheduled_hook(self::HOOK, [$id]);
                return $job->status;
            }
            // WP-Cron removes a single event before invoking its callback. Keep
            // a slower recovery event present while this process owns the batch;
            // an unexpected process exit must not strand the durable job.
            $this->schedule($id, self::RECOVERY_DELAY);
            $runner = new JobRunner($this->store());
            $upload = null;
            $until = microtime(true) + 20;
            $uploadSteps = 0;
            // Preparation has cheap metadata steps; retain the shared time budget
            // and the smaller network-operation cap, including during handoff.
            for ($count = 0; $count < self::MAX_BATCH_STEPS; ++$count) {
                $current = $this->current();
                if ($current === null || $current->id !== $id) { return 'mismatch'; }
                if (isset($current->checkpoint['phase'])) {
                    $step = new MediaPreparationStep((new WordPressMediaSourceFactory())->create(),
                        $current->checkpoint['directory'], ABSPATH);
                    $status = $step->tick($this->store(), $id);
                } else {
                    if (++$uploadSteps > self::MAX_UPLOAD_STEPS) { break; }
                    $upload ??= new MediaUploadStep($this->clientFactory === null
                        ? MediaS3Client::create($current->checkpoint['region'])
                        : ($this->clientFactory)($current->checkpoint['region']));
                    $status = $runner->tick($id, 'media', $upload);
                }
                if ($status !== 'running' || microtime(true) >= $until
                    || ($this->current()?->attempts ?? 0) > 0) {
                    break;
                }
            }
            if (in_array($status, ['succeeded', 'failed'], true)) {
                wp_clear_scheduled_hook(self::HOOK, [$id]);
            } else {
                // Replace the recovery event only after a bounded batch returned.
                // This also migrates an unfinished job from the former recurring
                // event to completion-paced single events.
                wp_clear_scheduled_hook(self::HOOK, [$id]);
                $this->schedule($id, self::NEXT_BATCH_DELAY);
            }
            return $status;
        } catch (Throwable $e) {
            return 'worker_unavailable';
        }
    }

    /**
     * Explicit failed-job cleanup only. Completed S3 objects are never deleted.
     *
     * @return array<string, mixed> Sanitized durable cleanup result.
     */
    public function cleanupFailedJob(string $expectedId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $expectedId) !== 1) {
            throw new RuntimeException('A valid failed media job ID is required.');
        }
        $store = $this->store();
        $pendingRecord = null;
        $pending = null;
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $observed = $store->read();
            $job = $observed === null ? null : BackupJob::decode($observed);
            if ($job === null || $job->id !== $expectedId || $job->type !== 'media'
                || $job->status !== 'failed') {
                throw new RuntimeException('Only the exact current failed media job can be cleaned up.');
            }
            $cleanup = $job->checkpoint['cleanup'] ?? null;
            if (is_array($cleanup) && ($cleanup['state'] ?? null) === 'completed') {
                return $this->validateCompletedCleanup($cleanup);
            }
            if (is_array($cleanup) && ($cleanup['state'] ?? null) === 'pending') {
                $pending = $this->validatePendingCleanup($cleanup, $job->id);
                $pendingRecord = $observed;
                break;
            }
            if ($cleanup !== null) {
                throw new RuntimeException('Invalid failed media cleanup state.');
            }
            $pending = $this->preparePendingCleanup($job);
            $replacement = $this->failedWithCleanup($job, $pending)->encode();
            if ($store->compareAndSwap($observed, $replacement)) {
                $pendingRecord = $replacement;
                break;
            }
        }
        if ($pendingRecord === null || $pending === null) {
            throw new RuntimeException('Failed media cleanup state changed concurrently.');
        }

        wp_clear_scheduled_hook(self::HOOK, [$expectedId]);
        if ($pending['multipart']) {
            $client = $this->clientFactory === null
                ? MediaS3Client::create($pending['region'])
                : ($this->clientFactory)($pending['region']);
            $client->request('AbortMultipartUpload', [
                'Bucket' => $pending['bucket'], 'Key' => $pending['key'],
                'UploadId' => $pending['upload_id'],
            ], time() + 30);
        }
        MediaFailedJobCleanup::remove($pending['directory'], $pending['work_identity']);

        $completed = ['version' => 1, 'state' => 'completed',
            'multipart_recorded' => $pending['multipart'],
            'multipart_aborted_or_missing' => true,
            'private_work_removed_or_missing' => true,
            'completed_objects_retained' => true,
            'bucket' => $pending['bucket'] ?? '', 'run_prefix' => $pending['run_prefix']];
        $pendingJob = BackupJob::decode($pendingRecord);
        $replacement = $this->failedWithCleanup($pendingJob, $completed)->encode();
        if (! $store->compareAndSwap($pendingRecord, $replacement)) {
            $current = $store->read();
            $job = $current === null ? null : BackupJob::decode($current);
            $result = $job?->checkpoint['cleanup'] ?? null;
            if ($job === null || $job->id !== $expectedId || ! is_array($result)
                || ($result['state'] ?? null) !== 'completed') {
                throw new RuntimeException('Failed media cleanup result changed concurrently.');
            }
            return $this->validateCompletedCleanup($result);
        }
        return $completed;
    }

    /** @return array<string, mixed> */
    private function preparePendingCleanup(BackupJob $job): array
    {
        $state = $job->checkpoint;
        $directory = $state['directory'] ?? null;
        $identity = $state['work_identity'] ?? null;
        $root = $state['metadata']['root'] ?? $state['root'] ?? null;
        if (! is_string($directory) || ! is_array($identity) || ! is_string($root)
            || MediaFailedJobCleanup::captureIdentity($directory, [ABSPATH, $root]) !== $identity) {
            throw new RuntimeException('Failed media workspace cannot be proven.');
        }
        $prefix = $state['prefix'] ?? null;
        if (! is_string($prefix) || strlen($prefix) + 128 > 1024 || str_contains($prefix, "\0")
            || ($prefix !== '' && (! str_ends_with($prefix, '/') || str_starts_with($prefix, '/')))) {
            throw new RuntimeException('Invalid failed media destination.');
        }
        $runPrefix = $prefix . 'backups/media/' . $job->id . '/';
        $pending = ['version' => 1, 'state' => 'pending', 'directory' => $directory,
            'work_identity' => $identity, 'run_prefix' => $runPrefix,
            'multipart' => isset($state['upload_id'])];
        if ($pending['multipart']) {
            $region = $state['region'] ?? null;
            $bucket = $state['bucket'] ?? null;
            $key = $state['upload_key'] ?? null;
            $uploadId = $state['upload_id'];
            if (! is_string($region) || $region === '' || ! is_string($bucket) || $bucket === ''
                || ! is_string($key) || ! is_string($uploadId) || $uploadId === ''
                || ! $this->validCleanupKey($key, $runPrefix)) {
                throw new RuntimeException('Failed multipart target cannot be proven.');
            }
            $pending += ['region' => $region, 'bucket' => $bucket, 'key' => $key,
                'upload_id' => $uploadId];
        }
        return $pending;
    }

    /** @return array<string, mixed> */
    private function validatePendingCleanup(array $cleanup, string $id): array
    {
        $keys = ['version', 'state', 'directory', 'work_identity', 'run_prefix', 'multipart'];
        $multipart = $cleanup['multipart'] ?? null;
        if ($multipart === true) { array_push($keys, 'region', 'bucket', 'key', 'upload_id'); }
        sort($keys);
        $actual = array_keys($cleanup);
        sort($actual);
        if ($actual !== $keys || ($cleanup['version'] ?? null) !== 1
            || ($cleanup['state'] ?? null) !== 'pending' || ! is_string($cleanup['directory'] ?? null)
            || ! is_array($cleanup['work_identity'] ?? null) || ! is_string($cleanup['run_prefix'] ?? null)
            || ! is_bool($multipart) || ! $this->validRunPrefix($cleanup['run_prefix'], $id)) {
            throw new RuntimeException('Invalid pending media cleanup state.');
        }
        if ($multipart && (! is_string($cleanup['region']) || $cleanup['region'] === ''
            || ! is_string($cleanup['bucket']) || $cleanup['bucket'] === ''
            || ! is_string($cleanup['key']) || ! is_string($cleanup['upload_id'])
            || $cleanup['upload_id'] === ''
            || ! $this->validCleanupKey($cleanup['key'], $cleanup['run_prefix']))) {
            throw new RuntimeException('Invalid pending multipart cleanup target.');
        }
        return $cleanup;
    }

    /** @return array<string, mixed> */
    private function validateCompletedCleanup(array $cleanup): array
    {
        $keys = ['version', 'state', 'multipart_recorded', 'multipart_aborted_or_missing',
            'private_work_removed_or_missing', 'completed_objects_retained', 'bucket', 'run_prefix'];
        $actual = array_keys($cleanup);
        sort($keys);
        sort($actual);
        if ($actual !== $keys || ($cleanup['version'] ?? null) !== 1
            || ($cleanup['state'] ?? null) !== 'completed'
            || ! is_bool($cleanup['multipart_recorded'] ?? null)
            || ($cleanup['multipart_aborted_or_missing'] ?? null) !== true
            || ($cleanup['private_work_removed_or_missing'] ?? null) !== true
            || ($cleanup['completed_objects_retained'] ?? null) !== true
            || ! is_string($cleanup['bucket'] ?? null) || ! is_string($cleanup['run_prefix'] ?? null)) {
            throw new RuntimeException('Invalid completed media cleanup state.');
        }
        return $cleanup;
    }

    private function validRunPrefix(string $runPrefix, string $id): bool
    {
        $suffix = 'backups/media/' . $id . '/';
        return $runPrefix === $suffix || str_ends_with($runPrefix, '/' . $suffix);
    }

    private function validCleanupKey(string $key, string $runPrefix): bool
    {
        return $key === $runPrefix . 'inventory.jsonl'
            || preg_match('/^' . preg_quote($runPrefix, '/') . 'files\/[a-f0-9]{64}$/D', $key) === 1;
    }

    private function failedWithCleanup(BackupJob $job, array $cleanup): BackupJob
    {
        return new BackupJob($job->id, 'media', 'failed', ['cleanup' => $cleanup],
            $job->processedFiles, $job->processedBytes, attempts: $job->attempts,
            errorCode: $job->errorCode);
    }

    private function schedule(string $id, int $delay = self::NEXT_BATCH_DELAY): void
    {
        if (wp_next_scheduled(self::HOOK, [$id]) === false) {
            $result = wp_schedule_single_event(time() + $delay, self::HOOK, [$id], true);
            if (is_wp_error($result) || $result === false) {
                throw new RuntimeException('Media job is saved but scheduling failed.');
            }
        }
    }

    /** Delete only this plugin's archived local job metadata, never S3/filesystem data. */
    public static function purgeArchivedResults(): void
    {
        global $wpdb;
        $pattern = $wpdb->esc_like('secure_s3_storage_media_result_') . '%';
        do {
            // Bounded batches; delete_option handles WordPress cache invalidation.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
                $pattern,
            ));
            if (! is_array($names) || $wpdb->last_error !== '') {
                throw new RuntimeException('Unable to read archived media jobs.');
            }
            foreach ($names as $name) {
                if (! delete_option($name)) {
                    throw new RuntimeException('Unable to remove archived media job metadata.');
                }
            }
        } while ($names !== []);
    }

    public static function assertOutsideWebRoot(string $path): void
    {
        $path = realpath($path);
        $root = realpath(ABSPATH);
        if ($path === false || $root === false) {
            throw new RuntimeException('Media work directory does not exist.');
        }
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        if ($path === $root || str_starts_with($path, $root . '/')) {
            throw new RuntimeException('Media work directory must be outside the web root.');
        }
    }

    private function store(): JobStore
    {
        if ($this->jobStore !== null) {
            return $this->jobStore;
        }
        global $wpdb;
        return new WordPressJobStore($wpdb);
    }
}
