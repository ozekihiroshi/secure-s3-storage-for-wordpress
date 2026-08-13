<?php

use SecureS3StorageForWordpress\Cron\BackupScheduleManager;

require __DIR__ . '/../../vendor/autoload.php';

if (! function_exists('wp_next_scheduled')) {
    fwrite(
        STDERR,
        "WordPress is not loaded.\n"
    );

    exit(1);
}

$manager = new BackupScheduleManager();

try {
    echo 'Initially scheduled: '
        . ($manager->isScheduled() ? 'yes' : 'no')
        . PHP_EOL;

    $manager->scheduleDaily();

    echo 'After first schedule: '
        . ($manager->isScheduled() ? 'yes' : 'no')
        . PHP_EOL;

    $firstTimestamp =
        $manager->getNextScheduledTimestamp();

    echo 'First timestamp: '
        . ($firstTimestamp ?? 'none')
        . PHP_EOL;

    /*
     * Call again. This must not create a duplicate event.
     */
    $manager->scheduleDaily();

    $secondTimestamp =
        $manager->getNextScheduledTimestamp();

    echo 'After second schedule: '
        . ($manager->isScheduled() ? 'yes' : 'no')
        . PHP_EOL;

    echo 'Second timestamp: '
        . ($secondTimestamp ?? 'none')
        . PHP_EOL;

    echo 'Duplicate prevented: '
        . (
            $firstTimestamp === $secondTimestamp
                ? 'yes'
                : 'no'
        )
        . PHP_EOL;

    $manager->unschedule();

    echo 'After unschedule: '
        . ($manager->isScheduled() ? 'yes' : 'no')
        . PHP_EOL;

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'BackupScheduleManager test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}