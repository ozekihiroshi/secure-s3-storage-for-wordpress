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

const KEEP_COUNT = 1;

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

$prefix =
    isset($options['prefix'])
        ? (string) $options['prefix']
        : '';

if ($region === '' || $bucket === '') {
    fwrite(
        STDERR,
        "AWS region and S3 bucket are required.\n"
    );

    exit(1);
}

echo PHP_EOL;
echo "Secure S3 Storage Retention Dry Run" . PHP_EOL;
echo "===================================" . PHP_EOL;
echo PHP_EOL;

echo "WARNING: DRY RUN ONLY" . PHP_EOL;
echo "No S3 objects will be deleted." . PHP_EOL;
echo PHP_EOL;

echo "Region:     {$region}" . PHP_EOL;
echo "Bucket:     {$bucket}" . PHP_EOL;
echo "Prefix:     {$prefix}" . PHP_EOL;
echo "Keep count: " . KEEP_COUNT . PHP_EOL;
echo PHP_EOL;

try {
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

    $policy =
        new RetentionPolicy(
            KEEP_COUNT
        );

    $candidates =
        $manager->findDeletionCandidates(
            $bucket,
            $prefix,
            $policy
        );

    echo "Deletion candidates: "
        . count($candidates)
        . PHP_EOL;

    echo PHP_EOL;

    if ($candidates === []) {
        echo "No deletion candidates found."
            . PHP_EOL;

        echo PHP_EOL;
        echo "Dry run completed." . PHP_EOL;

        exit(0);
    }

    foreach (
        $candidates as $index => $candidate
    ) {
        $number =
            $index + 1;

        $timestamp =
            $candidate
                ->getLastModified()
                ->getTimestamp();

        $wpTime =
            wp_date(
                'Y-m-d H:i:s T',
                $timestamp
            );

        echo "Candidate #{$number}" . PHP_EOL;
        echo "  Bucket: "
            . $candidate->getBucket()
            . PHP_EOL;

        echo "  Key:    "
            . $candidate->getKey()
            . PHP_EOL;

        echo "  Size:   "
            . size_format(
                $candidate->getSizeBytes()
            )
            . PHP_EOL;

        echo "  Last modified: "
            . $wpTime
            . PHP_EOL;

        echo PHP_EOL;
    }

    echo "DRY RUN COMPLETE" . PHP_EOL;
    echo "No S3 objects were deleted." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Retention dry run failed: "
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}