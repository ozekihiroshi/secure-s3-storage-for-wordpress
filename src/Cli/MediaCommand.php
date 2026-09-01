<?php

namespace SecureS3StorageForWordpress\Cli;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\WordPressMediaSourceFactory;
use Throwable;
use WP_CLI;

/** Media worker commands; the database backup command is unchanged. */
final class MediaCommand
{
    /**
     * Prepare an inventory and multipart checksums without S3 access.
     *
     * ## OPTIONS
     *
     * <work-parent>
     * : Existing persistent private directory outside the web root and uploads.
     */
    public function prepare(array $args): void
    {
        try {
            $parent = realpath($args[0]);
            MediaJobController::assertOutsideWebRoot($parent === false ? '' : $parent);
            $plan = MediaUploadPlan::prepare((new WordPressMediaSourceFactory())->create(), $parent);
            WP_CLI::line($plan->directory);
        } catch (Throwable $e) {
            WP_CLI::error(__('Media preparation failed. Check private storage and source readability.', 'ozeki-database-backup-for-s3'));
        }
    }

    /**
     * Submit a prepared plan using the current AWS settings.
     *
     * ## OPTIONS
     *
     * <plan-directory>
     * : Private directory printed by media prepare.
     */
    public function start(array $args): void
    {
        try {
            $path = realpath($args[0]);
            if ($path === false) {
                throw new RuntimeException('Media plan does not exist.');
            }
            $job = (new MediaJobController())->start(new MediaUploadPlan($path));
            WP_CLI::line('job_id=' . $job->id);
            WP_CLI::line('status=' . $job->status);
        } catch (Throwable $e) {
            WP_CLI::error(__('Media submission could not complete. Check media status before retrying.', 'ozeki-database-backup-for-s3'));
        }
    }

    /**
     * Queue media preparation and upload in the background.
     *
     * ## OPTIONS
     *
     * <work-parent>
     * : Existing persistent directory outside the web root and uploads.
     */
    public function enqueue(array $args): void
    {
        try {
            $parent = realpath($args[0]);
            if ($parent === false) { throw new RuntimeException('Work directory does not exist.'); }
            $job = (new MediaJobController())->enqueuePreparation($parent);
            WP_CLI::line('job_id=' . $job->id);
            WP_CLI::line('status=' . $job->status);
        } catch (Throwable $e) {
            WP_CLI::error(__('Media submission could not complete. Check media status before retrying.', 'ozeki-database-backup-for-s3'));
        }
    }

    /** Execute one bounded step; useful with system Cron when WP-Cron traffic is absent. */
    public function tick(): void
    {
        try {
            $controller = new MediaJobController();
            $job = $controller->current();
            WP_CLI::line($job === null ? 'missing' : $controller->run($job->id));
        } catch (Throwable $e) {
            WP_CLI::error(__('Media worker unavailable.', 'ozeki-database-backup-for-s3'));
        }
    }

    /** Show safe counters, without credentials, upload identifiers or local paths. */
    public function status(): void
    {
        try {
            $job = (new MediaJobController())->current();
            WP_CLI::line(wp_json_encode($job === null ? ['status' => 'missing'] : [
                'id' => $job->id, 'type' => $job->type, 'status' => $job->status,
                'files' => $job->processedFiles, 'bytes' => $job->processedBytes, 'error' => $job->errorCode,
                'phase' => $job->checkpoint['phase'] ?? 'upload',
            ]));
            if ($job?->errorCode === 'preparation_requires_cli') {
                WP_CLI::warning(__('A directory exceeded the background scan time budget. Use media prepare followed by media start in CLI; no partial backup was published.', 'ozeki-database-backup-for-s3'));
            }
        } catch (Throwable $e) {
            WP_CLI::error(__('Unable to read media job status.', 'ozeki-database-backup-for-s3'));
        }
    }

    /**
     * Remove only one failed job's recorded multipart and private work.
     *
     * ## OPTIONS
     *
     * <job-id>
     * : Exact current failed media job ID shown by `media status`.
     *
     * [--yes]
     * : Confirm the explicit cleanup operation.
     */
    public function cleanup(array $args, array $assocArgs): void
    {
        try {
            if (count($args) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $args[0]) !== 1) {
                throw new RuntimeException('Exact failed media job ID required.');
            }
            WP_CLI::confirm(__('Abort this job\'s recorded incomplete multipart upload and remove only its private work directory? Completed backup objects are retained.', 'ozeki-database-backup-for-s3'), $assocArgs);
            (new MediaJobController())->cleanupFailedJob($args[0]);
            WP_CLI::success(__('Failed media job cleanup completed. Existing completed backup objects were retained.', 'ozeki-database-backup-for-s3'));
        } catch (Throwable $e) {
            WP_CLI::error(__('Failed upload cleanup could not complete.', 'ozeki-database-backup-for-s3'));
        }
    }
}
