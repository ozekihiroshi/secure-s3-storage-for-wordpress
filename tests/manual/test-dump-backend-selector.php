<?php

use SecureS3StorageForWordpress\Backup\Database\DumpBackendSelector;

require __DIR__ . '/../../vendor/autoload.php';

//$selector = new DumpBackendSelector();
$selector = new DumpBackendSelector([
    'definitely-not-installed',
]);


$backend = $selector->select();

echo 'Selected backend: '
    . get_class($backend)
    . PHP_EOL;

echo 'Backend name: '
    . $selector->getSelectedBackendName()
    . PHP_EOL;

echo 'Detected utility: '
    . ($selector->getDetectedUtility() ?? 'none')
    . PHP_EOL;