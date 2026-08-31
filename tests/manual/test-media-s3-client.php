<?php

// Actual installed AWS SDK, but a local HTTP handler: never access AWS/network.
require __DIR__ . '/../../vendor/autoload.php';

use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\Backup\Job\RetryableJobException;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$httpStatus = 200;
$responseHeaders = [];
$observed = null;
$sdk = new S3Client([
    'version' => 'latest', 'region' => 'ap-northeast-1', 'retries' => 0,
    'credentials' => ['key' => 'TESTKEY', 'secret' => 'TESTSECRET'],
    'http_handler' => static function ($request, $options) use (&$httpStatus, &$responseHeaders, &$observed) {
        $observed = ['body' => (string) $request->getBody(), 'headers' => $request->getHeaders(), 'options' => $options];
        if ($httpStatus >= 400) {
            return Create::rejectionFor(['exception' => new RuntimeException('Mock HTTP failure'),
                'response' => new Response($httpStatus, $responseHeaders)]);
        }
        return Create::promiseFor(new Response($httpStatus, $responseHeaders));
    },
]);
$client = new MediaS3Client($sdk);
$body = fopen('php://temp', 'w+b');
fwrite($body, 'beforePAYLOADafter');
$hash = base64_encode(hash('sha256', 'PAYLOAD', true));
$responseHeaders = ['x-amz-checksum-sha256' => $hash];
$result = $client->request('UploadPart', ['Bucket' => 'test-bucket', 'Key' => 'part',
    'UploadId' => 'dummy-upload', 'PartNumber' => 1, 'Body' => $body, 'RangeOffset' => 6,
    'ContentLength' => 7, 'ChecksumSHA256' => $hash], time() + 60);
$check($observed['body'] === 'PAYLOAD', 'SDK must send only the selected byte range');
$check(($result['ChecksumSHA256'] ?? null) === $hash, 'SDK parses part checksum');
$check($observed['options']['timeout'] <= 20 && $observed['options']['connect_timeout'] <= 5, 'HTTP budget bounded');
$check(isset($observed['headers']['Authorization']), 'SDK signs the request');
$httpStatus = 404;
$check(($client->request('HeadObject', ['Bucket' => 'test-bucket', 'Key' => 'missing'], time() + 60)['missing'] ?? false), 'Only missing head is recoverable');
$httpStatus = 412;
$check(($client->request('PutObject', ['Bucket' => 'test-bucket', 'Key' => 'exists', 'Body' => '', 'IfNoneMatch' => '*'], time() + 60)['exists'] ?? false), 'Conditional conflict recovered by later head check');
$httpStatus = 503;
try {
    $client->request('HeadObject', ['Bucket' => 'test-bucket', 'Key' => 'failed'], time() + 60);
    throw new LogicException('503 did not request a retry');
} catch (RetryableJobException $e) { $check(true, '503 retry'); }
$httpStatus = 403;
try {
    $client->request('HeadObject', ['Bucket' => 'test-bucket', 'Key' => 'denied'], time() + 60);
    throw new LogicException('403 accepted');
} catch (RuntimeException $e) {
    $check(! $e instanceof RetryableJobException && $e->getMessage() === 'Media S3 operation failed.', '403 safe terminal failure');
}
echo 'PASS media SDK checks=' . $checks . PHP_EOL;
