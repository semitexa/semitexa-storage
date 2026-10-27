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
    /** @var array<string, string|false> what the shell exported, put back after each test */
    private array $environment = [];

    /** Both change the URL or the timeout these tests pin: a value the shell exported failed them. */
    protected function setUp(): void
    {
        foreach (['STORAGE_S3_TRANSFER_TIMEOUT', 'STORAGE_S3_PATH_STYLE'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv("{$name}=");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    /** The response type allows a header name with no value; reading its last one was a warning. */
    #[Test]
    public function a_header_with_no_value_is_left_out(): void
    {
        $http = (new FakeHttpClient())->queue(new HttpResponse(200, '', ['content-type' => [], 'content-length' => ['5']]));
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $meta = self::driver($http)->stat('a.txt');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame([5, null], [$meta?->size, $meta?->mimeType]);
    }
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
        $options = $http->recordedOptions()[0];
        self::assertSame(2, $options->retry->maxAttempts);
        self::assertSame(PHP_INT_MAX, $options->maxResponseBytes, 'an object of any size comes back, as before');
        self::assertFalse($options->decodeContent, 'an object kept gzip-encoded comes back as stored');
    }

    /** 30 s for the whole transfer cut a healthy download of a large object off mid-way. */
    #[Test]
    public function an_object_transfer_has_minutes_and_a_head_has_seconds(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200), HttpResponse::make(200));
        $driver = self::driver($http);

        $driver->put('a.txt', 'x', 'text/plain');
        $driver->exists('a.txt');

        self::assertSame([600.0, 30.0], array_map(static fn ($o): float => $o->timeout, $http->recordedOptions()));
    }

    /** As a host name a dotted bucket is one level too deep for the *.s3 certificate. */
    #[Test]
    public function a_bucket_name_with_a_dot_is_addressed_in_the_path(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        (new S3Driver('assets.example.com', 'eu-west-1', 'https://s3.eu-west-1.amazonaws.com', 'key', 'secret', $http))->put('a.txt', 'x', 'text/plain');

        self::assertSame('https://s3.eu-west-1.amazonaws.com/assets.example.com/a.txt', $http->recorded()[0]->url ?? null);
    }

    /** An endpoint behind a path lost it in requests while url() kept it. */
    #[Test]
    public function an_endpoint_behind_a_path_keeps_it_in_every_request(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));
        $driver = new S3Driver('bucket', 'us-east-1', 'https://gateway.test/s3/', 'key', 'secret', $http, pathStyle: true);

        $driver->put('a.txt', 'x', 'text/plain');

        self::assertSame('https://gateway.test/s3/bucket/a.txt', $http->recorded()[0]->url ?? null);
        self::assertSame('https://gateway.test/s3/bucket/a.txt', $driver->url('a.txt'), 'url() and the request agree');
    }

    /** Empty, the hook sent its own Content-Type and the signature no longer matched. */
    #[Test]
    public function an_empty_mime_type_is_sent_as_octet_stream(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        self::driver($http)->put('a.bin', 'x', '');

        self::assertSame('application/octet-stream', $http->recorded()[0]->header('Content-Type'));
    }

    /** libcurl folds `a/../b` and the hook does not: one path signed, another sent. */
    #[Test]
    public function a_key_with_a_dot_segment_is_refused(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(StorageException::class);
        try {
            self::driver($http)->put('a/../b.txt', 'x', 'text/plain');
        } finally {
            $http->assertSentCount(0);
        }
    }

    #[Test]
    public function a_mime_type_with_a_line_break_is_a_storage_failure(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(StorageException::class);

        try {
            self::driver($http)->put('a.txt', 'x', "text/plain\r\nX-Injected: 1");
        } finally {
            $http->assertSentCount(0);
        }
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
