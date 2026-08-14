<?php

use SecureS3StorageForWordpress\Backup\CompleteStreamWriter;

require __DIR__ . '/../../vendor/autoload.php';

$source = "partial-write-test-???-??";
$captured = '';
$writeCalls = 0;

try {
    CompleteStreamWriter::writeAll(
        $source,
        static function (string $remaining) use (
            &$captured,
            &$writeCalls
        ): int {
            $piece = substr($remaining, 0, 3);
            $captured .= $piece;
            ++$writeCalls;

            return strlen($piece);
        },
        'Partial write test failed.'
    );

    if ($captured !== $source || $writeCalls <= 1) {
        throw new RuntimeException(
            'Partial writes were not completed correctly.'
        );
    }

    foreach ([0, false] as $invalidResult) {
        $failedAsExpected = false;

        try {
            CompleteStreamWriter::writeAll(
                'x',
                static fn (string $remaining): int|false => $invalidResult,
                'Expected write failure.'
            );
        } catch (RuntimeException $e) {
            $failedAsExpected = true;
        }

        if (! $failedAsExpected) {
            throw new RuntimeException(
                'A zero or false write result was accepted.'
            );
        }
    }

    echo "Complete stream writer verification: OK\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'Complete stream writer verification failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}
