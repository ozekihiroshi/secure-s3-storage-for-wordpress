<?php

namespace SecureS3StorageForWordpress\Cli;

use RuntimeException;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\WordPressMediaSourceFactory;
use Throwable;
use WP_CLI;

/** Experimental media worker commands; the database backup command is unchanged. */
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
            ]));
        } catch (Throwable $e) {
            WP_CLI::error(__('Unable to read media job status.', 'ozeki-database-backup-for-s3'));
        }
    }

    /** Abort only the current failed job's recorded multipart upload; retain backup objects. */
    public function cleanup(): void
    {
        try {
            (new MediaJobController())->abortFailedUpload();
            WP_CLI::success(__('Failed upload cleanup completed. Existing backup objects were retained.', 'ozeki-database-backup-for-s3'));
        } catch (Throwable $e) {
            WP_CLI::error(__('Failed upload cleanup could not complete.', 'ozeki-database-backup-for-s3'));
        }
    }
}
