<?php

use SecureS3StorageForWordpress\Backup\Database\DumpBackendSelector;

require __DIR__ . '/../../vendor/autoload.php';

echo 'proc_open exists: '
    . (
        function_exists('proc_open')
            ? 'yes'
            : 'no'
    )
    . PHP_EOL;

$selector =
    new DumpBackendSelector();

$backend =
    $selector->select();

echo 'Selected backend: '
    . get_class($backend)
    . PHP_EOL;

echo 'Backend name: '
    . $selector->getSelectedBackendName()
    . PHP_EOL;

echo 'Detected utility: '
    . (
        $selector->getDetectedUtility()
        ?? 'none'
    )
    . PHP_EOL;

if (function_exists('proc_open')) {
    fwrite(
        STDERR,
        "Test environment error: proc_open is still available.\n"
    );

    exit(1);
}

if (
    $selector->getSelectedBackendName()
    !== 'php'
) {
    fwrite(
        STDERR,
        "Test failed: PHP fallback was not selected.\n"
    );

    exit(1);
}

echo 'Fallback verification: OK'
    . PHP_EOL;