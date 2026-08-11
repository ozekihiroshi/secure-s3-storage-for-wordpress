<?php

namespace SecureS3StorageForWordpress\Admin;

class SettingsPage
{
    private const OPTION_NAME = 'secure_s3_storage_settings';
    private const OPTION_GROUP = 'secure_s3_storage';
    private const PAGE_SLUG = 'secure-s3-storage';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
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

    private function get_options(): array
    {
        $options = get_option(self::OPTION_NAME, []);

        return is_array($options) ? $options : [];
    }
}