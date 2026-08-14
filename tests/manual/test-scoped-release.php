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

fwrite(STDOUT, "Scoped release collision test passed.\n");
