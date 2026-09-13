<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Exception\StorageException;

/**
 * Reads used to collapse every non-200 into "the object is not there": a 403
 * AccessDenied, a 429, a 500 and a real 404 all answered null / false. That
 * hides an outage behind a missing file, defeats retry (nothing to retry on)
 * and sends the caller looking for a deleted object that was never deleted.
 *
 * Only 404 may mean missing. Everything else must say what happened.
 */
final class S3DriverReadErrorSemanticsTest extends TestCase
{
    /** @return list<array{int}> */
    public static function failureStatuses(): array
    {
        return [[403], [429], [500], [503]];
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function get_throws_rather_than_reporting_a_missing_object(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("S3 GET 'uploads/report.pdf' failed with HTTP {$status}");
        $driver->get('uploads/report.pdf');
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function exists_throws_rather_than_answering_false(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $driver->exists('uploads/report.pdf');
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function stat_throws_rather_than_answering_null(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $driver->stat('uploads/report.pdf');
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function read_stream_throws_rather_than_answering_null(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $driver->readStream('uploads/report.pdf');
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function describe_throws_rather_than_answering_null(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $driver->describe('uploads/report.pdf');
    }

    #[Test]
    #[DataProvider('failureStatuses')]
    public function delete_throws_rather_than_reporting_nothing_deleted(int $status): void
    {
        $driver = new FakeReadS3Driver(status: $status);

        $this->expectException(StorageException::class);
        $driver->delete('uploads/report.pdf');
    }

    #[Test]
    public function a_404_still_means_the_object_is_not_there(): void
    {
        $driver = new FakeReadS3Driver(status: 404);

        self::assertNull($driver->get('gone'));
        self::assertFalse($driver->exists('gone'));
        self::assertNull($driver->stat('gone'));
        self::assertNull($driver->readStream('gone'));
        self::assertNull($driver->describe('gone'));
        self::assertFalse($driver->delete('gone'));
    }

    #[Test]
    public function a_present_object_reads_back_unchanged(): void
    {
        $driver = new FakeReadS3Driver(status: 200, body: 'bytes');

        self::assertSame('bytes', $driver->get('there'));
        self::assertTrue($driver->exists('there'));
        self::assertTrue($driver->delete('there'));

        $stream = $driver->readStream('there');
        self::assertIsResource($stream);
        self::assertSame('bytes', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function an_empty_object_is_still_an_object(): void
    {
        $driver = new FakeReadS3Driver(status: 200, body: '');

        self::assertSame('', $driver->get('empty'), 'an empty body is content, not absence');
        self::assertTrue($driver->exists('empty'));
    }

    #[Test]
    public function a_delete_of_an_already_absent_object_is_not_an_error(): void
    {
        // S3 answers 204 to DELETE whether or not the object was there.
        $driver = new FakeReadS3Driver(status: 204);

        self::assertTrue($driver->delete('whatever'));
    }

    #[Test]
    public function the_message_names_the_operation_and_the_status_but_not_the_body(): void
    {
        $driver = new FakeReadS3Driver(
            status: 403,
            body: '<Error><Code>AccessDenied</Code><Message>secret-bucket-policy-detail</Message></Error>'
        );

        try {
            $driver->get('uploads/report.pdf');
            self::fail('expected a StorageException');
        } catch (StorageException $e) {
            self::assertStringContainsString('GET', $e->getMessage());
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringNotContainsString('secret-bucket-policy-detail', $e->getMessage(),
                'the response body may carry bucket policy or credentials detail and must not be echoed');
        }
    }

    /**
     * S3 answers 403 for a MISSING key when the principal has no
     * s3:ListBucket, so on such a bucket strictness turns every ordinary miss
     * into an exception. The lenient reading is opt-in, and the exception says
     * how to opt in rather than leaving it to be discovered.
     */
    #[Test]
    public function a_forbidden_read_can_be_read_as_absent_when_the_deployment_says_so(): void
    {
        $driver = new FakeReadS3Driver(status: 403);

        putenv('STORAGE_S3_MISSING_IS_FORBIDDEN=1');
        try {
            self::assertNull($driver->get('uploads/report.pdf'));
            self::assertFalse($driver->exists('uploads/report.pdf'));
            self::assertNull($driver->stat('uploads/report.pdf'));
        } finally {
            putenv('STORAGE_S3_MISSING_IS_FORBIDDEN');
        }
    }

    #[Test]
    public function the_forbidden_error_names_the_way_out(): void
    {
        $driver = new FakeReadS3Driver(status: 403);

        try {
            $driver->get('uploads/report.pdf');
            self::fail('expected a StorageException');
        } catch (StorageException $e) {
            self::assertStringContainsString('s3:ListBucket', $e->getMessage());
            self::assertStringContainsString('STORAGE_S3_MISSING_IS_FORBIDDEN', $e->getMessage());
        }
    }

    #[Test]
    public function the_opt_out_does_not_excuse_any_other_status(): void
    {
        putenv('STORAGE_S3_MISSING_IS_FORBIDDEN=1');
        try {
            $this->expectException(StorageException::class);
            (new FakeReadS3Driver(status: 500))->get('uploads/report.pdf');
        } finally {
            putenv('STORAGE_S3_MISSING_IS_FORBIDDEN');
        }
    }
}
/**
 * Canned HTTP result, no network — the same seam the write-failure test uses.
 */
final class FakeReadS3Driver extends S3Driver
{
    public function __construct(
        private readonly int $status,
        private readonly string $body = '',
    ) {
        parent::__construct(bucket: 'test-bucket', region: 'us-east-1', endpoint: 'https://s3.example.invalid', key: 'k', secret: 's');
    }

    protected function executeHttp(string $url, string $method, array $curlHeaders, string $body, array &$responseHeaders): array
    {
        $responseHeaders = ['content-length' => (string) strlen($this->body), 'content-type' => 'application/pdf'];

        return [
            'body' => $this->body,
            'errno' => 0,
            'error' => '',
            'status' => $this->status,
        ];
    }
}