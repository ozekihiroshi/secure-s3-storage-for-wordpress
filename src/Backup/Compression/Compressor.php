<?php

namespace SecureS3StorageForWordpress\Backup\Compression;

interface Compressor
{
    public function compress(string $sourcePath): CompressedResult;
}