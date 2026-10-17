<?php

use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

/** Actual SDK serialization with synthetic HTTP responses. No network or real credentials. */
final class DatabaseS3TestTransport
{
    public array $objects = [];
    public array $calls = [];
    public string $fault = '';

    public function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest', 'region' => 'us-east-1', 'retries' => 0,
            'credentials' => ['key' => 'TESTKEY', 'secret' => 'TESTSECRET'],
            'http_handler' => function ($request, $options) {
                $method = $request->getMethod();
                $key = (string) $request->getUri()->getPath();
                $this->calls[] = $method;
                if ($this->fault === 'denied') {
                    return Create::rejectionFor(['exception' => new RuntimeException('Synthetic denial'), 'response' => new Response(403, [], '<Error><Code>AccessDenied</Code></Error>')]);
                }
                if ($method === 'PUT') {
                    $body = (string) $request->getBody();
                    if ($request->getHeaderLine('If-None-Match') !== '*') { throw new RuntimeException('Missing conditional write'); }
                    if (isset($this->objects[$key])) {
                        return Create::rejectionFor(['exception' => new RuntimeException('Synthetic conflict'), 'response' => new Response(412, [], '<Error><Code>PreconditionFailed</Code></Error>')]);
                    }
                    $hash = base64_encode(hash('sha256', $body, true));
                    if ($request->getHeaderLine('x-amz-checksum-sha256') !== $hash) { throw new RuntimeException('Upload checksum mismatch'); }
                    $this->objects[$key] = $body;
                    return Create::promiseFor(new Response(200, ['ETag' => '"not-a-sha256"', 'x-amz-checksum-sha256' => $hash]));
                }
                if (! isset($this->objects[$key])) { return Create::promiseFor(new Response(404)); }
                $body = $this->objects[$key];
                $headers = ['Content-Length' => (string) strlen($body), 'x-amz-checksum-sha256' => base64_encode(hash('sha256', $body, true))];
                if ($this->fault === 'size') { $headers['Content-Length'] = (string) (strlen($body) + 1); }
                if ($this->fault === 'hash') { $headers['x-amz-checksum-sha256'] = base64_encode(str_repeat('x', 32)); }
                if ($this->fault === 'missing-hash') { unset($headers['x-amz-checksum-sha256']); }
                if ($method === 'HEAD') {
                    if ($request->getHeaderLine('x-amz-checksum-mode') !== 'ENABLED') { throw new RuntimeException('Missing checksum mode'); }
                    return Create::promiseFor(new Response(200, $headers));
                }
                if ($this->fault === 'body') { $body[0] = chr(ord($body[0]) ^ 1); }
                if ($this->fault === 'truncated') { $body = substr($body, 0, -1); }
                if ($this->fault === 'oversize') { $body .= 'extra'; }
                if (! empty($options['stream'])) {
                    // Real HTTP streaming bodies cannot rewind for SDK checksum validation.
                    return Create::promiseFor(new Response(200, $headers, new \GuzzleHttp\Psr7\NoSeekStream(\GuzzleHttp\Psr7\Utils::streamFor($body))));
                }
                if (isset($options['sink'])) {
                    $sink = \GuzzleHttp\Psr7\Utils::streamFor($options['sink']);
                    $sink->write($body);
                    $sink->rewind();
                    return Create::promiseFor(new Response(200, $headers, $sink));
                }
                return Create::promiseFor(new Response(200, $headers, $body));
            },
        ]);
    }
}
