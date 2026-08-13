<?php

namespace SecureS3StorageForWordpress;

use SecureS3StorageForWordpress\Admin\SettingsPage;
use SecureS3StorageForWordpress\Cron\DatabaseBackupCronHandler;

final class Plugin
{
    public function run(): void
    {
        $settingsPage =
            new SettingsPage();

        $settingsPage->register();

        $cronHandler =
            new DatabaseBackupCronHandler();

        $cronHandler->register();
    }
}
