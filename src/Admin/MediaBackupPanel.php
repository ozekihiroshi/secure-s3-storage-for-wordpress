<?php

namespace SecureS3StorageForWordpress\Admin;

use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\MediaWorkConfiguration;
use Throwable;

/** Admin requests only enqueue or read; no browser request executes a worker. */
final class MediaBackupPanel
{
    public const START_ACTION = 'odbfs3_media_start';
    public const STATUS_ACTION = 'odbfs3_media_status';
    private const NOTICE_PREFIX = 'odbfs3_media_notice_';

    public function __construct(private ?MediaJobController $controller = null)
    {
        $this->controller ??= new MediaJobController();
    }

    public function register(): void
    {
        add_action('admin_post_' . self::START_ACTION, [$this, 'handleStart']);
        add_action('wp_ajax_' . self::STATUS_ACTION, [$this, 'handleStatus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScript']);
    }

    public function enqueueScript(string $hook): void
    {
        if ($hook !== 'settings_page_ozeki-database-backup-for-s3' || ! current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_script('odbfs3-media-admin', plugins_url('src/Admin/media-backup.js',
            dirname(__DIR__, 2) . '/ozeki-database-backup-for-s3.php'), [], '0.2.0-dev-1', true);
    }

    private function authorize(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'ozeki-database-backup-for-s3'), '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('A POST request is required.', 'ozeki-database-backup-for-s3'), '', ['response' => 405]);
        }
    }

    public function handleStart(): void
    {
        $this->authorize();
        check_admin_referer(self::START_ACTION, 'odbfs3_media_nonce');
        $notice = 'start_failed';
        // A generation token rejects stale forms even after an earlier job finished.
        $expected = isset($_POST['odbfs3_previous_job']) && is_string($_POST['odbfs3_previous_job'])
            ? sanitize_text_field(wp_unslash($_POST['odbfs3_previous_job'])) : null;
        if ($expected === null || ! preg_match('/^(none|[a-f0-9]{32})$/D', $expected)) {
            wp_die(esc_html__('Reload the settings page before starting a backup.', 'ozeki-database-backup-for-s3'), '', ['response' => 400]);
        }
        try {
            $current = $this->controller->current();
            if (($current?->id ?? 'none') !== $expected || ($current !== null && ($current->status === 'failed' || ! $current->terminal()))) {
                $notice = 'changed';
            } else {
                $directory = (new MediaWorkConfiguration())->directory();
                $this->controller->enqueuePreparation($directory, $expected === 'none' ? '' : $expected);
                $notice = 'queued';
            }
        } catch (Throwable $e) {
            // A scheduling error can occur AFTER a durable job was saved. Never
            // automatically retry or expose exception paths, SDK data or secrets.
        }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, 300);
        wp_safe_redirect($this->pageUrl());
        exit;
    }

    public function handleStatus(): void
    {
        $this->authorize();
        check_ajax_referer(self::STATUS_ACTION, 'odbfs3_media_nonce');
        nocache_headers();
        try {
            $state = $this->snapshot();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => __('Unable to read media status. Reload the page before starting another backup.', 'ozeki-database-backup-for-s3')], 503);
            return;
        }
        wp_send_json_success($state);
    }

    /** Explicit display allowlist: never serialize the job/checkpoint into HTML/JSON. */
    public function snapshot(): array
    {
        $job = $this->controller->current();
        if ($job !== null && $job->type !== 'media') {
            throw new \RuntimeException('The job slot belongs to another backup type.');
        }
        $status = $job?->status ?? 'missing';
        $phase = $job?->checkpoint['phase'] ?? 'upload';
        $phaseLabel = match ($phase) {
            'enumerate' => __('Listing files', 'ozeki-database-backup-for-s3'),
            'sort_runs', 'sort_merge' => __('Sorting file list', 'ozeki-database-backup-for-s3'),
            'files', 'file_hash', 'file_hashed', 'parts' => __('Preparing checksums', 'ozeki-database-backup-for-s3'),
            'validate_directories' => __('Checking source changes', 'ozeki-database-backup-for-s3'),
            default => __('Uploading and verifying', 'ozeki-database-backup-for-s3'),
        };
        $statusLabel = match ($status) {
            'queued' => __('Queued', 'ozeki-database-backup-for-s3'),
            'running' => __('Running', 'ozeki-database-backup-for-s3'),
            'succeeded' => __('Succeeded', 'ozeki-database-backup-for-s3'),
            'failed' => __('Failed', 'ozeki-database-backup-for-s3'),
            default => __('No media backup yet', 'ozeki-database-backup-for-s3'),
        };
        $message = '';
        if ($status === 'succeeded') {
            $message = __('All files and the completion marker were verified in S3. This does not verify a complete WordPress site restoration.', 'ozeki-database-backup-for-s3');
        } elseif ($status === 'failed') {
            $message = match ($job->errorCode) {
                'preparation_requires_cli' => __('A directory exceeded the background scan budget. Ask the server administrator to use media prepare followed by media start in WP-CLI.', 'ozeki-database-backup-for-s3'),
                'recovery_exhausted' => __('Recovery attempts were exhausted. Check server resources and private storage before starting a new backup.', 'ozeki-database-backup-for-s3'),
                default => __('The backup stopped. Do not submit another media job from this page. Ask the server administrator to inspect media status and run the explicit WP-CLI cleanup when required. Incomplete data is not a completed backup.', 'ozeki-database-backup-for-s3'),
            };
        } elseif ($job !== null && wp_next_scheduled(MediaJobController::HOOK, [$job->id]) === false) {
            $message = __('The job is saved but no Cron event is scheduled. Ask the server administrator to check WordPress Cron; do not submit another job.', 'ozeki-database-backup-for-s3');
        }
        $prepared = $job?->checkpoint['files'] ?? $job?->checkpoint['metadata']['files'] ?? null;
        return [
            'id' => $job?->id ?? '', 'status' => $status, 'status_label' => $statusLabel,
            'active' => $job !== null && ! $job->terminal(),
            'phase_label' => $job === null || $job->terminal() ? '—' : $phaseLabel,
            'prepared_files' => is_int($prepared) && $prepared >= 0 ? number_format_i18n($prepared) : '—',
            'uploaded_files' => number_format_i18n($job?->processedFiles ?? 0),
            'uploaded_bytes' => size_format($job?->processedBytes ?? 0, 2),
            'message' => $message,
        ];
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) { return; }
        $available = true;
        try { $state = $this->snapshot(); } catch (Throwable $e) { $state = null; $available = false; }
        if (($state['status'] ?? '') === 'failed') { $available = false; }
        try { (new MediaWorkConfiguration())->directory(); } catch (Throwable $e) { $available = false; }
        $options = get_option('secure_s3_storage_settings', []);
        if (! is_array($options) || ! is_string($options['region'] ?? null) || $options['region'] === ''
            || ! is_string($options['bucket'] ?? null) || $options['bucket'] === '') { $available = false; }
        $noticeKey = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($noticeKey);
        if ($notice !== false) { delete_transient($noticeKey); }
        ?>
        <hr>
        <section id="odbfs3-media" aria-labelledby="odbfs3-media-heading"
            data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce(self::STATUS_ACTION)); ?>"
            data-action="<?php echo esc_attr(self::STATUS_ACTION); ?>">
            <h2 id="odbfs3-media-heading"><?php esc_html_e('Media Backup', 'ozeki-database-backup-for-s3'); ?></h2>
            <?php if (is_string($notice)) : ?>
                <p role="status"><?php echo esc_html(match ($notice) {
                    'queued' => __('Media backup queued. You may close this page.', 'ozeki-database-backup-for-s3'),
                    'changed' => __('A job already exists or this form is out of date. Review the current status before starting again.', 'ozeki-database-backup-for-s3'),
                    default => __('The start request could not complete. A job may already be saved; check its status before retrying. Check private storage and saved AWS settings.', 'ozeki-database-backup-for-s3'),
                }); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e('Back up files under the WordPress uploads directory using the saved AWS settings. WordPress Cron performs preparation and upload after this request; closing the browser does not cancel the job. Cron requires site traffic or a server scheduler.', 'ozeki-database-backup-for-s3'); ?></p>
            <p><?php esc_html_e('Keep uploads unchanged during a backup. New or changed files or directories can stop it. Media backups are separate from database backups; there is no automatic media schedule, retention or private-work cleanup yet.', 'ozeki-database-backup-for-s3'); ?></p>
            <details>
                <summary><?php esc_html_e('Private storage requirements', 'ozeki-database-backup-for-s3'); ?></summary>
                <p><?php esc_html_e('Ask the server administrator to define ODBFS3_MEDIA_WORK_DIR in wp-config.php as an existing persistent directory owned by the WordPress PHP user, with mode 0700, outside all public web directories and uploads. Symbolic links and Windows ACL-only storage are not supported. Do not use a publicly served path or a temporary directory that may be cleared.', 'ozeki-database-backup-for-s3'); ?></p>
                <p><?php esc_html_e('Initialize the normal uploads year/month directory before the first backup of a new site. The backup itself does not create source directories. Ensure sufficient free disk space; private checkpoint files and incomplete S3 data remain until separately cleaned up.', 'ozeki-database-backup-for-s3'); ?></p>
            </details>
            <?php if (! $available) : ?>
                <p><?php esc_html_e('Starting is unavailable. Check saved AWS settings, single-site support, readable job status and private storage configuration. A failed media job also requires server-side inspection before another start. Existing job status remains available when it can be read.', 'ozeki-database-backup-for-s3'); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::START_ACTION); ?>">
                <input type="hidden" name="odbfs3_previous_job" value="<?php echo esc_attr(($state['id'] ?? '') === '' ? 'none' : $state['id']); ?>">
                <?php wp_nonce_field(self::START_ACTION, 'odbfs3_media_nonce'); ?>
                <button type="submit" class="button button-primary" data-media-start <?php disabled(! $available || ($state['active'] ?? true)); ?>><?php esc_html_e('Start Media Backup', 'ozeki-database-backup-for-s3'); ?></button>
            </form>
            <p><a href="<?php echo esc_url($this->pageUrl()); ?>"><?php esc_html_e('Refresh status / prepare a new start form', 'ozeki-database-backup-for-s3'); ?></a></p>
            <p data-media-poll-error hidden role="status"><?php esc_html_e('Status could not be refreshed. The job may still be running. Reload this page to reconnect.', 'ozeki-database-backup-for-s3'); ?></p>
            <div aria-live="polite" aria-atomic="true">
                <?php if ($state === null) : ?>
                    <p><?php esc_html_e('Unable to read media status.', 'ozeki-database-backup-for-s3'); ?></p>
                <?php else : ?>
                    <table class="widefat striped"><tbody>
                    <?php foreach ([
                        'status_label' => __('Status', 'ozeki-database-backup-for-s3'),
                        'phase_label' => __('Phase', 'ozeki-database-backup-for-s3'),
                        'prepared_files' => __('Files with prepared checksums', 'ozeki-database-backup-for-s3'),
                        'uploaded_files' => __('Uploaded files', 'ozeki-database-backup-for-s3'),
                        'uploaded_bytes' => __('Uploaded size', 'ozeki-database-backup-for-s3'),
                        'id' => __('Job ID', 'ozeki-database-backup-for-s3'),
                    ] as $key => $label) : ?>
                        <tr><th scope="row"><?php echo esc_html($label); ?></th><td data-media-field="<?php echo esc_attr($key); ?>"><?php echo esc_html($state[$key]); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p data-media-field="message"><?php echo esc_html($state['message']); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function pageUrl(): string
    {
        return admin_url('options-general.php?page=ozeki-database-backup-for-s3') . '#odbfs3-media';
    }
}
