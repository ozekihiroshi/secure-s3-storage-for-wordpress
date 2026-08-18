<?php

use SecureS3StorageForWordpress\Aws\S3ClientFactory;
use SecureS3StorageForWordpress\Aws\S3Storage;

require __DIR__ . '/../../vendor/autoload.php';

$region = 'ap-northeast-1';
$bucket = 'ceri-secure-s3-storage-test';
$key = 'wordpress-test/backups/manual-s3-storage-test.gz';

$tempPath = '/tmp/secure-s3-storage-manual-test.gz';

file_put_contents(
    $tempPath,
    gzencode(
        'Ozeki Database Backup for S3 manual upload test: ' . gmdate('c')
    )
);

$clientFactory = new S3ClientFactory();
$client = $clientFactory->create($region);

$storage = new S3Storage($client);

try {
    $result = $storage->upload(
        $tempPath,
        $bucket,
        $key
    );

    echo "S3 upload successful.\n";
    echo "Bucket: " . $result->getBucket() . "\n";
    echo "Key: " . $result->getKey() . "\n";
    echo "Size: " . $result->getSizeBytes() . " bytes\n";
    echo "ETag: " . ($result->getEtag() ?? 'none') . "\n";

    $client->headObject([
        'Bucket' => $bucket,
        'Key' => $key,
    ]);

    echo "S3 object exists: yes\n";

    $client->deleteObject([
        'Bucket' => $bucket,
        'Key' => $key,
    ]);

    echo "Test object deleted.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'S3Storage test failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);

} finally {
    if (is_file($tempPath)) {
        unlink($tempPath);
    }
}