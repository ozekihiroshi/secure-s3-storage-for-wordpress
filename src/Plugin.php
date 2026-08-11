<?php

namespace SecureS3StorageForWordpress;

use SecureS3StorageForWordpress\Admin\SettingsPage;

class Plugin
{
    public function run(): void
    {
        $settings_page = new SettingsPage();
        $settings_page->register();
    }
}
