<?php

namespace SecureS3StorageForWordpress\Admin;

use DateTimeImmutable;
use SecureS3StorageForWordpress\Aws\ConnectionTester;
use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;
use SecureS3StorageForWordpress\Backup\BackupService;
use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\DatabaseBackupService;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryEntry;
use SecureS3StorageForWordpress\Backup\History\BackupHistoryRepository;
use SecureS3StorageForWordpress\Cron\BackupScheduleManager;
use SecureS3StorageForWordpress\WordPress\WordPressDatabaseConnectionFactory;
use Throwable;

class SettingsPage
{
    private const OPTION_NAME = 'secure_s3_storage_settings';
    private const OPTION_GROUP = 'secure_s3_storage';
    private const PAGE_SLUG = 'secure-s3-storage';

    private const TEST_ACTION =
        'secure_s3_storage_test_connection';

    private const BACKUP_ACTION =
        'secure_s3_storage_backup_database';

    private const BACKUP_NOTICE_PREFIX =
        'secure_s3_storage_backup_notice_';

    private const BACKUP_SCHEDULE_DISABLED =
        'disabled';

    private const BACKUP_SCHEDULE_DAILY =
        'daily';

    public function register(): void
    {
        add_action(
            'admin_menu',
            [$this, 'add_settings_page']
        );

        add_action(
            'admin_init',
            [$this, 'register_settings']
        );

        add_action(
            'admin_post_' . self::TEST_ACTION,
            [$this, 'handle_test_connection']
        );

        add_action(
            'admin_post_' . self::BACKUP_ACTION,
            [$this, 'handle_database_backup']
        );

        add_action(
            'update_option_' . self::OPTION_NAME,
            [$this, 'handle_settings_updated'],
            10,
            2
        );
    }

    public function add_settings_page(): void
    {
        add_options_page(
            'Secure S3 Storage',
            'Secure S3 Storage',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [],
            ]
        );

        add_settings_section(
            'secure_s3_storage_aws',
            'AWS Configuration',
            [$this, 'render_section_description'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'region',
            'AWS Region',
            [$this, 'render_region_field'],
            self::PAGE_SLUG,
            'secure_s3_storage_aws'
        );

        add_settings_field(
            'bucket',
            'S3 Bucket',
            [$this, 'render_bucket_field'],
            self::PAGE_SLUG,
            'secure_s3_storage_aws'
        );

        add_settings_field(
            'prefix',
            'S3 Prefix',
            [$this, 'render_prefix_field'],
            self::PAGE_SLUG,
            'secure_s3_storage_aws'
        );

        add_settings_section(
            'secure_s3_storage_backup_schedule',
            'Automatic Backup',
            [$this, 'render_backup_schedule_description'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'backup_schedule',
            'Schedule',
            [$this, 'render_backup_schedule_field'],
            self::PAGE_SLUG,
            'secure_s3_storage_backup_schedule'
        );
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>Secure S3 Storage</h1>

            <?php $this->render_test_notice(); ?>
            <?php $this->render_backup_notice(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>

            <hr>

            <h2>Authentication</h2>

            <p>
                AWS Default Credential Provider
            </p>

            <h2>Connection</h2>

            <p>
                Verify access to the configured S3 bucket and prefix.
            </p>

            <form
                method="post"
                action="<?php echo esc_url(
                    admin_url('admin-post.php')
                ); ?>"
            >
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo esc_attr(
                        self::TEST_ACTION
                    ); ?>"
                >

                <?php
                wp_nonce_field(
                    self::TEST_ACTION,
                    'secure_s3_storage_test_nonce'
                );

                submit_button(
                    'Test Connection',
                    'secondary',
                    'submit',
                    false
                );
                ?>
            </form>

            <hr>

            <h2>Database Backup</h2>

            <p>
                Create a compressed database backup and upload it
                to the configured Amazon S3 destination.
            </p>

            <form
                method="post"
                action="<?php echo esc_url(
                    admin_url('admin-post.php')
                ); ?>"
            >
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo esc_attr(
                        self::BACKUP_ACTION
                    ); ?>"
                >

                <?php
                wp_nonce_field(
                    self::BACKUP_ACTION,
                    'secure_s3_storage_backup_nonce'
                );

                submit_button(
                    'Backup Now',
                    'primary',
                    'submit',
                    false
                );
                ?>
            </form>

            <?php $this->render_backup_history(); ?>
        </div>
        <?php
    }

    public function render_section_description(): void
    {
        echo '<p>'
            . esc_html(
                'Configure the Amazon S3 destination used by this plugin.'
            )
            . '</p>';
    }

    public function render_region_field(): void
    {
        $options = $this->get_options();
        $value = $options['region'] ?? '';

        printf(
            '<input type="text" '
            . 'name="%1$s[region]" '
            . 'value="%2$s" '
            . 'class="regular-text" '
            . 'placeholder="ap-northeast-1">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function render_bucket_field(): void
    {
        $options = $this->get_options();
        $value = $options['bucket'] ?? '';

        printf(
            '<input type="text" '
            . 'name="%1$s[bucket]" '
            . 'value="%2$s" '
            . 'class="regular-text" '
            . 'placeholder="ceri-secure-s3-storage-test">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function render_prefix_field(): void
    {
        $options = $this->get_options();
        $value = $options['prefix'] ?? '';

        printf(
            '<input type="text" '
            . 'name="%1$s[prefix]" '
            . 'value="%2$s" '
            . 'class="regular-text" '
            . 'placeholder="wordpress-test/">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function render_backup_schedule_description(): void
    {
        echo '<p>'
            . esc_html(
                'Configure automatic database backups using WordPress Cron.'
            )
            . '</p>';
    }

    public function render_backup_schedule_field(): void
    {
        $options = $this->get_options();

        $value =
            $options['backup_schedule']
            ?? self::BACKUP_SCHEDULE_DISABLED;

        ?>
        <select
            name="<?php echo esc_attr(
                self::OPTION_NAME
            ); ?>[backup_schedule]"
        >
            <option
                value="<?php echo esc_attr(
                    self::BACKUP_SCHEDULE_DISABLED
                ); ?>"
                <?php
                selected(
                    $value,
                    self::BACKUP_SCHEDULE_DISABLED
                );
                ?>
            >
                Disabled
            </option>

            <option
                value="<?php echo esc_attr(
                    self::BACKUP_SCHEDULE_DAILY
                ); ?>"
                <?php
                selected(
                    $value,
                    self::BACKUP_SCHEDULE_DAILY
                );
                ?>
            >
                Daily
            </option>
        </select>

        <p class="description">
            <?php
            echo esc_html(
                'Daily backups are executed by WordPress Cron. '
                . 'Actual execution time may depend on site activity.'
            );
            ?>
        </p>
        <?php
    }

    public function sanitize_settings(array $input): array
    {
        $schedule = sanitize_key(
            $input['backup_schedule']
            ?? self::BACKUP_SCHEDULE_DISABLED
        );

        if (
            ! in_array(
                $schedule,
                [
                    self::BACKUP_SCHEDULE_DISABLED,
                    self::BACKUP_SCHEDULE_DAILY,
                ],
                true
            )
        ) {
            $schedule =
                self::BACKUP_SCHEDULE_DISABLED;
        }

        return [
            'region' => sanitize_text_field(
                $input['region'] ?? ''
            ),
            'bucket' => sanitize_text_field(
                $input['bucket'] ?? ''
            ),
            'prefix' => sanitize_text_field(
                $input['prefix'] ?? ''
            ),
            'backup_schedule' => $schedule,
        ];
    }

    public function handle_settings_updated(
        mixed $oldValue,
        mixed $newValue
    ): void {
        if (! is_array($newValue)) {
            return;
        }

        $schedule =
            $newValue['backup_schedule']
            ?? self::BACKUP_SCHEDULE_DISABLED;

        $manager =
            new BackupScheduleManager();

        try {
            if (
                $schedule
                === self::BACKUP_SCHEDULE_DAILY
            ) {
                $manager->scheduleDaily();

                return;
            }

            $manager->unschedule();
        } catch (Throwable $e) {
            /*
             * Do not interrupt WordPress settings saving because of
             * a scheduling error. Diagnostics can be added later.
             */
        }
    }

    public function handle_test_connection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html(
                    'You are not allowed to perform this action.'
                )
            );
        }

        check_admin_referer(
            self::TEST_ACTION,
            'secure_s3_storage_test_nonce'
        );

        $options = $this->get_options();

        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';
        $prefix = $options['prefix'] ?? '';

        if ($region === '' || $bucket === '') {
            $this->redirect_with_test_result(
                false,
                'Region and bucket are required.'
            );
        }

        try {
            $clientFactory =
                new S3ClientFactory();

            $client =
                $clientFactory->create(
                    $region
                );

            $tester =
                new ConnectionTester();

            $result =
                $tester->test(
                    $client,
                    $bucket,
                    $prefix
                );

            $this->redirect_with_test_result(
                (bool) $result['success'],
                (string) $result['message']
            );
        } catch (Throwable $e) {
            $this->redirect_with_test_result(
                false,
                'Unable to complete the S3 connection test.'
            );
        }
    }

    public function handle_database_backup(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html(
                    'You are not allowed to perform this action.'
                )
            );
        }

        check_admin_referer(
            self::BACKUP_ACTION,
            'secure_s3_storage_backup_nonce'
        );

        $options = $this->get_options();

        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';
        $prefix = $options['prefix'] ?? '';

        if ($region === '' || $bucket === '') {
            $message =
                'AWS region and S3 bucket are required.';

            $this->record_failed_backup(
                $message
            );

            $this->store_backup_notice(
                false,
                $message
            );

            $this->redirect_to_settings_page();
        }

        $databaseName = defined('DB_NAME')
            ? (string) DB_NAME
            : 'unknown';

        $backend = 'unknown';

        try {
            $connectionFactory =
                new WordPressDatabaseConnectionFactory();

            $databaseConnection =
                $connectionFactory->create();

            $databaseName =
                $databaseConnection->getDatabaseName();

            $clientFactory =
                new S3ClientFactory();

            $client =
                $clientFactory->create(
                    $region
                );

            $storage =
                new S3Storage(
                    $client
                );

            $backupService =
                new BackupService();

            $backend =
                $backupService
                    ->getSelectedBackendName();

            $compressor =
                new GzipCompressor();

            $databaseBackupService =
                new DatabaseBackupService(
                    $backupService,
                    $compressor,
                    $storage
                );

            $result =
                $databaseBackupService->backup(
                    $databaseConnection,
                    $bucket,
                    $prefix
                );

            $message = sprintf(
                'Database backup completed successfully. '
                . 'Backend: %s. '
                . 'S3 object: s3://%s/%s '
                . '(%d bytes).',
                $result->getBackend(),
                $result->getBucket(),
                $result->getKey(),
                $result->getSizeBytes()
            );

            $history =
                new BackupHistoryRepository();

            $history->add(
                new BackupHistoryEntry(
                    success: true,
                    createdAt: new DateTimeImmutable(
                        'now',
                        wp_timezone()
                    ),
                    databaseName:
                        $result->getDatabaseName(),
                    backend:
                        $result->getBackend(),
                    bucket:
                        $result->getBucket(),
                    key:
                        $result->getKey(),
                    sizeBytes:
                        $result->getSizeBytes(),
                    message:
                        'Backup completed successfully.'
                )
            );

            $this->store_backup_notice(
                true,
                $message
            );
        } catch (Throwable $e) {
            /*
             * Never expose database credentials,
             * AWS credentials, raw SQL, shell commands,
             * temporary filenames, or SDK exception details
             * in the administrator-facing message.
             */

            $message =
                'Database backup failed.';

            $history =
                new BackupHistoryRepository();

            $history->add(
                new BackupHistoryEntry(
                    success: false,
                    createdAt: new DateTimeImmutable(
                        'now',
                        wp_timezone()
                    ),
                    databaseName: $databaseName,
                    backend: $backend,
                    message: $message
                )
            );

            $this->store_backup_notice(
                false,
                $message
            );
        }

        $this->redirect_to_settings_page();
    }

    private function record_failed_backup(
        string $message
    ): void {
        $history =
            new BackupHistoryRepository();

        $history->add(
            new BackupHistoryEntry(
                success: false,
                createdAt: new DateTimeImmutable(
                    'now',
                    wp_timezone()
                ),
                databaseName: defined('DB_NAME')
                    ? (string) DB_NAME
                    : 'unknown',
                backend: 'unknown',
                message: $message
            )
        );
    }

    private function render_test_notice(): void
    {
        if (
            ! isset(
                $_GET['s3_test_status'],
                $_GET['s3_test_message']
            )
        ) {
            return;
        }

        $status =
            sanitize_key(
                wp_unslash(
                    $_GET['s3_test_status']
                )
            );

        $message =
            sanitize_text_field(
                wp_unslash(
                    $_GET['s3_test_message']
                )
            );

        $class =
            $status === 'success'
                ? 'notice notice-success'
                : 'notice notice-error';

        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    private function store_backup_notice(
        bool $success,
        string $message
    ): void {
        $userId =
            get_current_user_id();

        set_transient(
            self::BACKUP_NOTICE_PREFIX
                . $userId,
            [
                'success' => $success,
                'message' => $message,
            ],
            60
        );
    }

    private function render_backup_notice(): void
    {
        $userId =
            get_current_user_id();

        $key =
            self::BACKUP_NOTICE_PREFIX
            . $userId;

        $notice =
            get_transient($key);

        if (! is_array($notice)) {
            return;
        }

        delete_transient($key);

        $success =
            ! empty($notice['success']);

        $message =
            isset($notice['message'])
                ? (string) $notice['message']
                : '';

        $class =
            $success
                ? 'notice notice-success'
                : 'notice notice-error';

        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    private function render_backup_history(): void
    {
        $repository =
            new BackupHistoryRepository();

        $history =
            $repository->all();

        echo '<hr>';
        echo '<h2>Recent Backups</h2>';

        if ($history === []) {
            echo '<p>'
                . esc_html(
                    'No backup history yet.'
                )
                . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Status</th>';
        echo '<th>Database</th>';
        echo '<th>Backend</th>';
        echo '<th>Size</th>';
        echo '<th>S3 Object</th>';
        echo '<th>Message</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $success =
                ! empty($entry['success']);

            $date =
                $this->format_history_date(
                    isset($entry['createdAt'])
                        ? (string) $entry['createdAt']
                        : ''
                );

            $databaseName =
                isset($entry['databaseName'])
                    ? (string) $entry['databaseName']
                    : '-';

            $backend =
                isset($entry['backend'])
                    ? (string) $entry['backend']
                    : '-';

            $size = '-';

            if (
                isset($entry['sizeBytes'])
                && is_numeric(
                    $entry['sizeBytes']
                )
            ) {
                $size =
                    size_format(
                        (int) $entry['sizeBytes']
                    );
            }

            $s3Object = '-';

            if (
                ! empty($entry['bucket'])
                && ! empty($entry['key'])
            ) {
                $s3Object =
                    sprintf(
                        's3://%s/%s',
                        (string) $entry['bucket'],
                        (string) $entry['key']
                    );
            }

            $message =
                isset($entry['message'])
                    ? (string) $entry['message']
                    : '';

            echo '<tr>';

            printf(
                '<td>%s</td>',
                esc_html($date)
            );

            printf(
                '<td><strong>%s</strong></td>',
                esc_html(
                    $success
                        ? 'Success'
                        : 'Failed'
                )
            );

            printf(
                '<td>%s</td>',
                esc_html($databaseName)
            );

            printf(
                '<td>%s</td>',
                esc_html($backend)
            );

            printf(
                '<td>%s</td>',
                esc_html($size)
            );

            printf(
                '<td><code>%s</code></td>',
                esc_html($s3Object)
            );

            printf(
                '<td>%s</td>',
                esc_html($message)
            );

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    private function format_history_date(
        string $date
    ): string {
        if ($date === '') {
            return '-';
        }

        try {
            $dateTime =
                new DateTimeImmutable($date);

            return $dateTime
                ->setTimezone(
                    wp_timezone()
                )
                ->format(
                    'Y-m-d H:i:s'
                );
        } catch (Throwable $e) {
            return $date;
        }
    }

    private function redirect_with_test_result(
        bool $success,
        string $message
    ): void {
        $url =
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    's3_test_status' =>
                        $success
                            ? 'success'
                            : 'error',
                    's3_test_message' =>
                        $message,
                ],
                admin_url(
                    'options-general.php'
                )
            );

        wp_safe_redirect($url);
        exit;
    }

    private function redirect_to_settings_page(): void
    {
        $url =
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                ],
                admin_url(
                    'options-general.php'
                )
            );

        wp_safe_redirect($url);
        exit;
    }

    private function get_options(): array
    {
        $options =
            get_option(
                self::OPTION_NAME,
                []
            );

        return is_array($options)
            ? $options
            : [];
    }
}