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
            'secure-s3-storage backup',
            BackupCommand::class
        );
    }
}