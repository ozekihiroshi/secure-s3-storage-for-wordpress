<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php test-scoped-release.php /path/to/vendor/autoload.php\n");
    exit(2);
}

final class ForeignAwsS3Client
{
}

if (! class_alias(ForeignAwsS3Client::class, 'Aws\\S3\\S3Client')) {
    fwrite(STDERR, "Unable to simulate a foreign unscoped AWS SDK class.\n");
    exit(1);
}

require $argv[1];

if (! class_exists('SecureS3StorageForWordpress\\Plugin')) {
    fwrite(STDERR, "Plugin class is not autoloadable.\n");
    exit(1);
}

if (! class_exists('SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client')) {
    fwrite(STDERR, "Scoped AWS SDK class is not autoloadable.\n");
    exit(1);
}

if (! is_a('Aws\\S3\\S3Client', ForeignAwsS3Client::class, true)) {
    fwrite(STDERR, "The simulated foreign AWS SDK class was replaced.\n");
    exit(1);
}

$scopedClientClass =
    'SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client';
$scopedExceptionClass =
    'SecureS3StorageForWordpressVendor\\Aws\\S3\\Exception\\S3Exception';
$amzDate = '';
$credentialScope = '';

$client = new $scopedClientClass([
    'version' => 'latest',
    'region' => 'us-east-1',
    'retries' => 0,
    'credentials' => [
        'key' => 'scoped-release-test-key',
        'secret' => 'scoped-release-test-secret',
    ],
    'http_handler' => static function ($request) use (
        &$amzDate,
        &$credentialScope
    ) {
        $amzDate = $request->getHeaderLine('X-Amz-Date');
        $authorization = $request->getHeaderLine('Authorization');

        if (
            preg_match(
                '/Credential=[^\\/]+\\/([^, ]+)/',
                $authorization,
                $matches
            ) === 1
        ) {
            $credentialScope = $matches[1];
        }

        return SecureS3StorageForWordpressVendor\GuzzleHttp\Promise\Create::rejectionFor([
            'exception' => new RuntimeException(
                'Simulated scoped release HTTP failure.'
            ),
            'connection_error' => true,
        ]);
    },
]);

try {
    $client->headBucket([
        'Bucket' => 'scoped-release-test-bucket',
    ]);

    fwrite(STDERR, "The simulated S3 request unexpectedly succeeded.\n");
    exit(1);
} catch (Throwable $e) {
    if (! $e instanceof $scopedExceptionClass) {
        fwrite(
            STDERR,
            'The simulated S3 request threw an unexpected exception: '
                . get_class($e)
                . "\n"
        );
        exit(1);
    }
}

if (preg_match('/^\\d{8}T\\d{6}Z$/D', $amzDate) !== 1) {
    fwrite(STDERR, "The scoped AWS SDK generated an invalid request date.\n");
    exit(1);
}

if (
    preg_match(
        '/^\\d{8}\\/us-east-1\\/s3\\/aws4_request$/D',
        $credentialScope
    ) !== 1
) {
    fwrite(
        STDERR,
        "The scoped AWS SDK generated an invalid credential scope.\n"
    );
    exit(1);
}

if (! class_exists($scopedExceptionClass)) {
    fwrite(STDERR, "The scoped S3 exception class is not autoloadable.\n");
    exit(1);
}

fwrite(STDOUT, "Scoped release collision test passed.\n");
