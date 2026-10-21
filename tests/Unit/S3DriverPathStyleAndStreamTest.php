<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Exception\StorageException;

/**
 * Two findings of the storage guide: the driver put the bucket in the host
 * name only, so a self-hosted store at `http://minio:9000` was asked for
 * `test-bucket.minio` and never answered; and readStream() read the whole
 * object into a string before it handed a stream back.
 */
final class S3DriverPathStyleAndStreamTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('STORAGE_S3_PATH_STYLE');
    }

    #[Test]
    public function a_one_word_host_gets_the_bucket_in_the_path(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, 'x'));

        self::driver('http://minio:9000', $http)->get('reports/a b.pdf');

        $request = $http->recorded()[0];
        self::assertSame('http://minio:9000/test-bucket/reports/a%20b.pdf', $request->url);
        self::assertSame('minio:9000', $request->header('Host'));
    }

    #[Test]
    public function an_ip_or_localhost_endpoint_is_path_style_too(): void
    {
        foreach (['http://127.0.0.1:9000' => '127.0.0.1:9000', 'http://localhost:9000' => 'localhost:9000', 'http://[::1]:9000' => '[::1]:9000'] as $endpoint => $host) {
            $http = new FakeHttpClient();
            $http->queue(new HttpResponse(200, 'x'));
            self::driver($endpoint, $http)->get('k');

            self::assertSame($endpoint . '/test-bucket/k', $http->recorded()[0]->url, $endpoint);
        }
    }

    #[Test]
    public function aws_keeps_the_bucket_in_the_host_name(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, 'x'));

        self::driver('https://s3.eu-west-1.amazonaws.com', $http)->get('k');

        self::assertSame('https://test-bucket.s3.eu-west-1.amazonaws.com/k', $http->recorded()[0]->url);
    }

    #[Test]
    public function the_setting_decides_over_the_host(): void
    {
        putenv('STORAGE_S3_PATH_STYLE=1');
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, 'x'));
        self::driver('https://storage.example.com', $http)->get('k');
        self::assertSame('https://storage.example.com/test-bucket/k', $http->recorded()[0]->url);

        putenv('STORAGE_S3_PATH_STYLE=0');
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, 'x'));
        self::driver('http://minio:9000', $http)->get('k');
        self::assertSame('http://test-bucket.minio:9000/k', $http->recorded()[0]->url);
    }

    #[Test]
    public function read_stream_writes_the_object_as_it_arrives(): void
    {
        $object = str_repeat('0123456789', 300_000);
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, $object));

        $stream = self::driver('http://minio:9000', $http)->readStream('big.bin');

        self::assertIsResource($stream);
        self::assertNotNull($http->recordedOptions()[0]->onChunk, 'the body is streamed, not returned in one string');
        self::assertSame($object, stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function read_stream_of_a_missing_object_is_null_and_a_failure_throws(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(404, '<Error/>'), new HttpResponse(500, '<Error/>'), new HttpResponse(500, '<Error/>'), new HttpResponse(500, '<Error/>'));
        $driver = self::driver('http://minio:9000', $http);

        self::assertNull($driver->readStream('gone'));
        $this->expectException(StorageException::class);
        $driver->readStream('broken');
    }

    /** Under the hook a full temp disk cut the body at ~2 MiB and it still read as the object. */
    #[Test]
    public function a_stream_shorter_than_the_object_is_a_failure(): void
    {
        $http = new FakeHttpClient();
        $http->queue(
            new HttpResponse(200, 'abc', ['content-length' => ['3']]),
            new HttpResponse(200, 'abc', ['content-length' => ['10']]),
        );
        $driver = self::driver('http://objects:9000', $http);

        $whole = $driver->readStream('whole');
        self::assertIsResource($whole);
        self::assertSame('abc', stream_get_contents($whole));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('3 of 10 bytes arrived');
        $driver->readStream('cut');
    }

    #[Test]
    public function only_the_scheme_s_own_port_goes_unsaid(): void
    {
        foreach (['http://objects:443' => 'http://objects:443/test-bucket/k', 'https://objects:80' => 'https://objects:80/test-bucket/k', 'http://objects:80' => 'http://objects/test-bucket/k'] as $endpoint => $url) {
            $http = new FakeHttpClient();
            $http->queue(new HttpResponse(200, 'x'));
            self::driver($endpoint, $http)->get('k');

            self::assertSame($url, $http->recorded()[0]->url, $endpoint);
        }
    }

    private static function driver(string $endpoint, FakeHttpClient $http): S3Driver
    {
        return new S3Driver(bucket: 'test-bucket', region: 'us-east-1', endpoint: $endpoint, key: 'k', secret: 's', http: $http);
    }
}
