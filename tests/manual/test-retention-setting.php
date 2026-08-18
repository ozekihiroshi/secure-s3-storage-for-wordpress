<?php

declare(strict_types=1);

use SecureS3StorageForWordpress\Backup\Retention\RetentionSetting;

require __DIR__ . '/../../vendor/autoload.php';

$cases = [
    [0, 0],
    ['0', 0],
    [1, 1],
    ['2', 2],
    [7, 7],
    [14, 14],
    [30, 30],
    [365, 365],
    [(string) PHP_INT_MAX, PHP_INT_MAX],
    [-1, 0],
    ['-1', 0],
    ['1.5', 0],
    [1.5, 0],
    ['', 0],
    ['invalid', 0],
    [null, 0],
    [(string) PHP_INT_MAX . '0', 0],
];

foreach ($cases as [$input, $expected]) {
    $actual = RetentionSetting::normalize($input);

    if ($actual !== $expected) {
        fwrite(
            STDERR,
            sprintf(
                "Retention normalization failed for %s: expected %d, got %d.\n",
                var_export($input, true),
                $expected,
                $actual
            )
        );

        exit(1);
    }
}

fwrite(STDOUT, "Retention setting test passed.\n");
