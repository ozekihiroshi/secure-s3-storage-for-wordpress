<?php

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Backup\Retention\RetentionPolicy;
use SecureS3StorageForWordpress\Backup\Retention\S3BackupRetentionManager;

if (! defined('ABSPATH')) {
    fwrite(
        STDERR,
        "WordPress is not loaded.\n"
    );

    exit(1);
}

$options = get_option(
    'secure_s3_storage_settings',
    []
);

if (! is_array($options)) {
    fwrite(
        STDERR,
        "Secure S3 Storage settings are unavailable.\n"
    );

    exit(1);
}

$region =
    isset($options['region'])
    ? (string) $options['region']
    : '';

$bucket =
    isset($options['bucket'])
    ? (string) $options['bucket']
    : '';

if ($region === '' || $bucket === '') {
    fwrite(
        STDERR,
        "AWS region and S3 bucket are required.\n"
    );

    exit(1);
}

/*
 * Use a dedicated test prefix.
 *
 * This deliberately does not use the configured production
 * backup prefix such as wordpress-test/.
 */
$configuredPrefix =
    isset($options['prefix'])
    ? trim((string) $options['prefix'], '/')
    : '';

if ($configuredPrefix === '') {
    fwrite(
        STDERR,
        "Configured S3 prefix is required for this test.\n"
    );

    exit(1);
}

$testPrefix =
    $configuredPrefix
    . '/retention-delete-test-'
    . time();
$backupPrefix =
    $testPrefix . '/backups/database/';

$keys = [
    $backupPrefix
        . '2026/08/10/'
        . 'db-retention-test-20260810-030000.sql.gz',

    $backupPrefix
        . '2026/08/11/'
        . 'db-retention-test-20260811-030000.sql.gz',

    $backupPrefix
        . '2026/08/12/'
        . 'db-retention-test-20260812-030000.sql.gz',
];

echo PHP_EOL;
echo "Secure S3 Storage Retention Delete Test"
    . PHP_EOL;
echo "======================================="
    . PHP_EOL;
echo PHP_EOL;

echo "WARNING: THIS TEST DELETES TEST OBJECTS."
    . PHP_EOL;
echo "Production backup objects are not used."
    . PHP_EOL;
echo PHP_EOL;

echo "Region:      {$region}" . PHP_EOL;
echo "Bucket:      {$bucket}" . PHP_EOL;
echo "Test prefix: {$testPrefix}/" . PHP_EOL;
echo PHP_EOL;

$clientFactory =
    new S3ClientFactory();

$client =
    $clientFactory->create(
        $region
    );

$manager =
    new S3BackupRetentionManager(
        $client
    );

$createdKeys = [];

try {
    /*
     * Create three test objects.
     *
     * Retention sorting currently uses S3 LastModified,
     * so sleep between uploads to guarantee ordering.
     */
    foreach ($keys as $index => $key) {
        $content =
            'Secure S3 Storage retention delete test '
            . ($index + 1)
            . PHP_EOL;

        $client->putObject(
            [
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $content,
                'ContentType' => 'application/gzip',
            ]
        );

        $createdKeys[] = $key;

        echo "Created:"
            . PHP_EOL;
        echo "  {$key}"
            . PHP_EOL;

        if ($index < count($keys) - 1) {
            sleep(2);
        }
    }

    echo PHP_EOL;

    /*
     * Keep the newest two.
     * The oldest object should become the only candidate.
     */
    $policy =
        new RetentionPolicy(
            2
        );

    $candidates =
        $manager->findDeletionCandidates(
            $bucket,
            $testPrefix,
            $policy
        );

    echo "Keep count: "
        . $policy->getKeepCount()
        . PHP_EOL;

    echo "Deletion candidates: "
        . count($candidates)
        . PHP_EOL;

    echo PHP_EOL;

    foreach (
        $candidates as $index => $candidate
    ) {
        echo "Candidate #"
            . ($index + 1)
            . PHP_EOL;

        echo "  Key: "
            . $candidate->getKey()
            . PHP_EOL;

        echo "  Size: "
            . $candidate->getSizeBytes()
            . " bytes"
            . PHP_EOL;

        echo "  Last modified: "
            . wp_date(
                'Y-m-d H:i:s T',
                $candidate
                    ->getLastModified()
                    ->getTimestamp()
            )
            . PHP_EOL;

        echo PHP_EOL;
    }

    if (count($candidates) !== 1) {
        throw new RuntimeException(
            'Expected exactly one deletion candidate.'
        );
    }

    $expectedOldestKey =
        $keys[0];

    if (
        $candidates[0]->getKey()
        !== $expectedOldestKey
    ) {
        throw new RuntimeException(
            'Unexpected retention deletion candidate.'
        );
    }

    echo "Candidate verification: OK"
        . PHP_EOL;
    echo PHP_EOL;

    /*
     * Actual DeleteObject happens here.
     */
    $result =
        $manager->deleteCandidates(
            $candidates
        );

    echo "Deleted count: "
        . $result->getDeletedCount()
        . PHP_EOL;

    echo "Deleted bytes: "
        . $result->getDeletedBytes()
        . PHP_EOL;

    echo PHP_EOL;

    if ($result->getDeletedCount() !== 1) {
        throw new RuntimeException(
            'Expected exactly one deleted object.'
        );
    }

    /*
     * Confirm deleted object no longer exists.
     */
    $deletedStillExists = true;

    try {
        $client->headObject(
            [
                'Bucket' => $bucket,
                'Key' => $expectedOldestKey,
            ]
        );
    } catch (Throwable $e) {
        $deletedStillExists = false;
    }

    if ($deletedStillExists) {
        throw new RuntimeException(
            'Deleted test object still exists.'
        );
    }

    echo "Deleted object verification: OK"
        . PHP_EOL;

    /*
     * Confirm the two retained objects still exist.
     */
    foreach (
        array_slice($keys, 1) as $retainedKey
    ) {
        $client->headObject(
            [
                'Bucket' => $bucket,
                'Key' => $retainedKey,
            ]
        );

        echo "Retained object exists:"
            . PHP_EOL;
        echo "  {$retainedKey}"
            . PHP_EOL;
    }

    echo PHP_EOL;
    echo "Retention delete test successful."
        . PHP_EOL;
} catch (Throwable $e) {
    fwrite(
        STDERR,
        PHP_EOL
            . "Retention delete test failed: "
            . $e->getMessage()
            . PHP_EOL
    );

    exit(1);
} finally {
    /*
     * Clean up only the exact test keys created by this test.
     *
     * Do not perform prefix-wide deletion.
     */
    echo PHP_EOL;
    echo "Cleaning up test objects..."
        . PHP_EOL;

    foreach ($createdKeys as $key) {
        try {
            $client->deleteObject(
                [
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]
            );

            echo "Cleanup:"
                . PHP_EOL;
            echo "  {$key}"
                . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(
                STDERR,
                "Cleanup failed for test object: "
                    . $key
                    . PHP_EOL
            );
        }
    }

    echo "Cleanup complete."
        . PHP_EOL;
}
