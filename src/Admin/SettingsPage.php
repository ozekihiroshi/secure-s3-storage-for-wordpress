<?php

namespace SecureS3StorageForWordpress\Admin;

use SecureS3StorageForWordpress\Aws\ConnectionTester;
use SecureS3StorageForWordpress\Aws\S3ClientFactory;

class SettingsPage
{
    private const OPTION_NAME = 'secure_s3_storage_settings';
    private const OPTION_GROUP = 'secure_s3_storage';
    private const PAGE_SLUG = 'secure-s3-storage';
    private const TEST_ACTION = 'secure_s3_storage_test_connection';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action(
            'admin_post_' . self::TEST_ACTION,
            [$this, 'handle_test_connection']
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

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo esc_attr(self::TEST_ACTION); ?>"
                >

                <?php
                wp_nonce_field(
                    self::TEST_ACTION,
                    'secure_s3_storage_test_nonce'
                );
                ?>

                <?php
                submit_button(
                    'Test Connection',
                    'secondary',
                    'submit',
                    false
                );
                ?>
            </form>
        </div>
        <?php
    }

    public function render_section_description(): void
    {
        echo '<p>Configure the Amazon S3 destination used by this plugin.</p>';
    }

    public function render_region_field(): void
    {
        $options = $this->get_options();
        $value = $options['region'] ?? '';

        printf(
            '<input type="text" name="%1$s[region]" value="%2$s" class="regular-text" placeholder="ap-northeast-1">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function render_bucket_field(): void
    {
        $options = $this->get_options();
        $value = $options['bucket'] ?? '';

        printf(
            '<input type="text" name="%1$s[bucket]" value="%2$s" class="regular-text" placeholder="secure-s3-storage-test">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function render_prefix_field(): void
    {
        $options = $this->get_options();
        $value = $options['prefix'] ?? '';

        printf(
            '<input type="text" name="%1$s[prefix]" value="%2$s" class="regular-text" placeholder="wordpress-test/">',
            esc_attr(self::OPTION_NAME),
            esc_attr($value)
        );
    }

    public function sanitize_settings(array $input): array
    {
        return [
            'region' => sanitize_text_field($input['region'] ?? ''),
            'bucket' => sanitize_text_field($input['bucket'] ?? ''),
            'prefix' => sanitize_text_field($input['prefix'] ?? ''),
        ];
    }

    public function handle_test_connection(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You are not allowed to perform this action.');
        }

        check_admin_referer(
            self::TEST_ACTION,
            'secure_s3_storage_test_nonce'
        );

        $options = $this->get_options();

        $region = $options['region'] ?? '';
        $bucket = $options['bucket'] ?? '';

        if ($region === '' || $bucket === '') {
            $this->redirect_with_test_result(
                false,
                'Region and bucket are required.'
            );
        }

        $client_factory = new S3ClientFactory();
        $client = $client_factory->create($region);

        $tester = new ConnectionTester();
        $prefix = $options['prefix'] ?? '';

        $result = $tester->test(
            $client,
            $bucket,
            $prefix
        );

        $this->redirect_with_test_result(
            (bool) $result['success'],
            (string) $result['message']
        );
    }

    private function render_test_notice(): void
    {
        if (! isset($_GET['s3_test_status'], $_GET['s3_test_message'])) {
            return;
        }

        $status = sanitize_key(
            wp_unslash($_GET['s3_test_status'])
        );

        $message = sanitize_text_field(
            wp_unslash($_GET['s3_test_message'])
        );

        $class = $status === 'success'
            ? 'notice notice-success'
            : 'notice notice-error';

        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    private function redirect_with_test_result(
        bool $success,
        string $message
    ): void {
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                's3_test_status' => $success ? 'success' : 'error',
                's3_test_message' => $message,
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function get_options(): array
    {
        $options = get_option(self::OPTION_NAME, []);

        return is_array($options) ? $options : [];
    }
}