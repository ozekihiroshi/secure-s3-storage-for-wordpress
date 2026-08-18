<?php

namespace SecureS3StorageForWordpress\Cli;

use WP_CLI;

final class CommandRegistrar
{
    public function register(): void
    {
        if (
            ! defined('WP_CLI')
            || ! WP_CLI
        ) {
            return;
        }

        WP_CLI::add_command(
            'ozeki-database-backup-for-s3 backup',
            BackupCommand::class
        );

        WP_CLI::add_command(
            'ozeki-database-backup-for-s3 status',
            StatusCommand::class
        );
    }
}