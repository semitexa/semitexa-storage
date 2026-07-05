<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Exception\StorageException;

/**
 * S3Driver::put() used to discard the HTTP response status, so a 403
 * AccessDenied / 500 / SignatureDoesNotMatch was treated as a successful put —
 * silent data loss. And requestWithHeaders() never checked curl_exec(), so a
 * DNS/TLS/connection failure surfaced as status 0 (a write silently dropped;
 * a read indistinguishable from a 404). Both must now throw StorageException.
 *
 * The HTTP-status guard is driven through the executeHttp() seam (a canned
 * response, no network); the transport guard is driven by making the seam
 * report a curl failure.
 */
final class S3DriverWriteFailureTest extends TestCase
{
    #[Test]
    public function put_throws_on_a_non_2xx_response(): void
    {
        $driver = new FakeS3Driver(status: 403, body: '<Error>AccessDenied</Error>');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('S3 PUT returned HTTP 403');
        $driver->put('uploads/report.pdf', 'bytes', 'application/pdf');
    }

    #[Test]
    public function put_succeeds_on_a_2xx_response(): void
    {
        $driver = new FakeS3Driver(status: 200, body: '');

        $driver->put('uploads/report.pdf', 'bytes', 'application/pdf');

        self::assertSame(1, $driver->putCount, 'a 2xx put must complete without throwing');
    }

    #[Test]
    public function any_operation_throws_on_a_curl_transport_failure(): void
    {
        // errno 7 = CURLE_COULDNT_CONNECT; body false; status 0.
        $driver = new FakeS3Driver(status: 0, body: false, errno: 7, error: 'Connection refused');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage transport failed on PUT');
        $driver->put('uploads/report.pdf', 'bytes', 'application/pdf');
    }
}

/**
 * Replaces the raw HTTP exchange with a canned result so the guards in put() /
 * requestWithHeaders() are exercised without any network.
 */
final class FakeS3Driver extends S3Driver
{
    public int $putCount = 0;

    public function __construct(
        private readonly int $status,
        private readonly string|false $body,
        private readonly int $errno = 0,
        private readonly string $error = '',
    ) {
        parent::__construct(bucket: 'test-bucket', region: 'us-east-1', endpoint: 'https://s3.example.invalid', key: 'k', secret: 's');
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        parent::put($path, $contents, $mimeType);
        $this->putCount++;
    }

    protected function executeHttp(string $url, string $method, array $curlHeaders, string $body, array &$responseHeaders): array
    {
        return [
            'body' => $this->body,
            'errno' => $this->errno,
            'error' => $this->error,
            'status' => $this->status,
        ];
    }
}
