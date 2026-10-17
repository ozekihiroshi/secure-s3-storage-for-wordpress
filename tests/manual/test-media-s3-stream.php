<?php

require $argv[1] ?? __DIR__ . '/../../vendor/autoload.php';

use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use SecureS3StorageForWordpress\Aws\MediaS3Client;

$vendor = class_exists('SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client') ? 'SecureS3StorageForWordpressVendor\\' : '';
$clientClass = $vendor . S3Client::class;
$resultClass = $vendor . Result::class;
$promiseClass = $vendor . Create::class;
foreach ([false, true] as $fail) {
    $body = fopen('php://temp', 'w+b');
    fwrite($body, 'beforePAYLOADafter');
    $seen = null;
    $sdk = new $clientClass([
        'version' => 'latest', 'region' => 'ap-northeast-1',
        'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'retries' => 0,
        'handler' => static function ($command) use (&$seen, $fail, $promiseClass, $resultClass) {
            $seen = (string) $command['Body'];
            return $fail ? $promiseClass::rejectionFor(new RuntimeException('fixture failure'))
                : $promiseClass::promiseFor(new $resultClass([]));
        },
    ]);
    $adapter = new MediaS3Client($sdk);
    try {
        $adapter->request('PutObject', ['Bucket' => 'fixture-bucket', 'Key' => 'fixture',
            'Body' => $body, 'RangeOffset' => 6, 'ContentLength' => 7], time() + 30);
        if ($fail) { throw new LogicException('Expected failure.'); }
    } catch (RuntimeException $e) {
        if (!$fail || $e->getMessage() !== 'fixture failure') { throw $e; }
    }
    unset($adapter, $sdk);
    gc_collect_cycles();
    if ($seen !== 'PAYLOAD') { throw new RuntimeException('Upload range mismatch.'); }
    if (!is_resource($body)) { throw new RuntimeException('Adapter closed caller-owned stream.'); }
    rewind($body);
    if (stream_get_contents($body) !== 'beforePAYLOADafter') { throw new RuntimeException('Source changed.'); }
    fclose($body);
}
echo "PASS caller-owned upload streams survive success and failure.\n";
