<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Exception\StorageException;

/**
 * S3Driver over the shared HTTP client (ep-outbound-http-client): signed
 * requests go out through it, HEAD is a real HEAD (with curl's NOBODY, which
 * the driver's own curl call lacked, so exists() could wait out the timeout),
 * and a transport failure is still a StorageException, never a missing object.
 */
final class S3DriverHttpClientTest extends TestCase
{
    #[Test]
    public function a_put_is_signed_and_sent_through_the_client_with_retries_for_a_resendable_request(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        self::driver($http)->put('docs/a b.txt', 'hello', 'text/plain');

        $http->assertSent(static fn (HttpRequest $r): bool => $r->method === 'PUT'
            && $r->url === 'https://bucket.s3.test/docs/a%20b.txt'
            && $r->body === 'hello'
            && str_starts_with((string) $r->header('Authorization'), 'AWS4-HMAC-SHA256 Credential=key/')
            && $r->header('x-amz-content-sha256') === hash('sha256', 'hello'));
        self::assertSame(2, $http->recordedOptions()[0]->retry->maxAttempts);
    }

    #[Test]
    public function exists_asks_with_head_and_reads_the_status(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200), HttpResponse::make(404));
        $driver = self::driver($http);

        self::assertTrue($driver->exists('a.txt'));
        self::assertFalse($driver->exists('b.txt'));
        self::assertSame(['HEAD', 'HEAD'], array_map(static fn (HttpRequest $r): string => $r->method, $http->recorded()));
    }

    #[Test]
    public function a_transport_failure_is_not_a_missing_object(): void
    {
        $http = (new FakeHttpClient())->queue(new HttpTransportException(HttpTransportKind::Dns, 'GET', 'https://s3.test/bucket/a.txt', 'Could not resolve host'));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Could not resolve host');

        self::driver($http)->get('a.txt');
    }

    private static function driver(FakeHttpClient $http): S3Driver
    {
        return new S3Driver('bucket', 'us-east-1', 'https://s3.test', 'key', 'secret', $http);
    }
}
