<?php

namespace SecureS3StorageForWordpress\Aws;

use Aws\S3\S3Client;

class S3ClientFactory
{
    public function create(string $region): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region'  => $region,
        ]);
    }
}