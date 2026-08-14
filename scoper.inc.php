<?php

declare(strict_types=1);

return [
    'prefix' => 'SecureS3StorageForWordpressVendor',
    'php-version' => '8.1',
    'exclude-namespaces' => [
        'SecureS3StorageForWordpress',
    ],
    'exclude-classes' => [
        'WP_CLI',
    ],
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
];
