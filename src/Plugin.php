<?php

namespace SecureS3StorageForWordpress;

use SecureS3StorageForWordpress\Admin\SettingsPage;
use SecureS3StorageForWordpress\Cli\CommandRegistrar;
use SecureS3StorageForWordpress\Cron\DatabaseBackupCronHandler;

final class Plugin
{
    public function run(): void
    {
        (new \SecureS3StorageForWordpress\WordPress\MediaJobController())->register();

        $settingsPage =
            new SettingsPage();

        $settingsPage->register();

        $cronHandler =
            new DatabaseBackupCronHandler();

        $cronHandler->register();

        $commandRegistrar =
            new CommandRegistrar();

        $commandRegistrar->register();
    }
}