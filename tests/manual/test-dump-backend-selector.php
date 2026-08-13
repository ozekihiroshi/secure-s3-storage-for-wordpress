<?php

use SecureS3StorageForWordpress\Backup\Database\DumpBackendSelector;

require __DIR__ . '/../../vendor/autoload.php';

echo "Native detection test" . PHP_EOL;
echo "=====================" . PHP_EOL;

$nativeSelector =
    new DumpBackendSelector();

$nativeBackend =
    $nativeSelector->select();

echo 'Selected backend: '
    . get_class($nativeBackend)
    . PHP_EOL;

echo 'Backend name: '
    . $nativeSelector->getSelectedBackendName()
    . PHP_EOL;

echo 'Detected utility: '
    . (
        $nativeSelector->getDetectedUtility()
        ?? 'none'
    )
    . PHP_EOL;

echo PHP_EOL;

echo "Fallback test" . PHP_EOL;
echo "=============" . PHP_EOL;

$fallbackSelector =
    new DumpBackendSelector(
        [
            'definitely-not-installed',
        ]
    );

$fallbackBackend =
    $fallbackSelector->select();

echo 'Selected backend: '
    . get_class($fallbackBackend)
    . PHP_EOL;

echo 'Backend name: '
    . $fallbackSelector->getSelectedBackendName()
    . PHP_EOL;

echo 'Detected utility: '
    . (
        $fallbackSelector->getDetectedUtility()
        ?? 'none'
    )
    . PHP_EOL;