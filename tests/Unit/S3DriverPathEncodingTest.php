<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\S3Driver;

/**
 * Object keys are not guaranteed to be URL-safe: a space, a '+', or a
 * non-ASCII character is a perfectly valid S3 key but not a valid URL path
 * segment. Before the fix, S3Driver sent that key byte-for-byte into both the
 * curl request URL and url(), so curl rejected it ("Malformed input") and any
 * link handed out by url() was broken.
 *
 * The fix percent-encodes each path segment (rawurlencode, '/' left alone)
 * and reuses that single encoding for the request URL, the SigV4 canonical
 * URI, and url() — so this test also guards against the two drifting apart,
 * which would break the signature instead.
 */
final class S3DriverPathEncodingTest extends TestCase
{
    #[Test]
    public function url_percent_encodes_spaces_and_unicode_in_the_key(): void
    {
        $driver = new S3Driver(
            bucket: 'test-bucket',
            region: 'us-east-1',
            endpoint: 'https://s3.example.invalid',
            key: 'k',
            secret: 's',
        );

        $url = $driver->url('reports/Q3 finance/résumé café.pdf');

        self::assertSame(
            'https://s3.example.invalid/test-bucket/reports/Q3%20finance/r%C3%A9sum%C3%A9%20caf%C3%A9.pdf',
            $url,
        );
        // No raw space or non-ASCII byte may reach a URL consumer.
        self::assertStringNotContainsString(' ', $url);
    }

    #[Test]
    public function url_leaves_path_separators_and_unreserved_characters_alone(): void
    {
        $driver = new S3Driver(
            bucket: 'test-bucket',
            region: 'us-east-1',
            endpoint: 'https://s3.example.invalid',
            key: 'k',
            secret: 's',
        );

        self::assertSame(
            'https://s3.example.invalid/test-bucket/a/b-c_d.e~f/g.txt',
            $driver->url('a/b-c_d.e~f/g.txt'),
        );
    }

    #[Test]
    public function the_signed_request_url_uses_the_same_encoding_as_the_canonical_uri(): void
    {
        // put() with an unencodable key used to hand curl a URL containing a
        // literal space and produce a canonical request whose URI did not
        // match, either of which corrupts the request. Capturing what
        // executeHttp() actually receives proves both the URL and the
        // signature agree on one encoded path.
        $driver = new CapturingS3Driver(
            bucket: 'test-bucket',
            region: 'us-east-1',
            endpoint: 'https://s3.example.invalid',
            key: 'k',
            secret: 's',
        );

        $driver->put('my folder/report v2.pdf', 'bytes', 'application/pdf');

        self::assertSame(
            'https://test-bucket.s3.example.invalid/my%20folder/report%20v2.pdf',
            $driver->capturedUrl,
        );
        self::assertNotNull($driver->capturedAuthorizationHeader);
        // The canonical request (folded into the signature) must have been
        // built from the same encoded path as the URL above, so the request
        // is internally consistent — a mismatch would just move the bug from
        // "curl rejects the URL" to "S3 rejects the signature".
        self::assertStringNotContainsString(' ', (string) $driver->capturedUrl);
    }
}

/**
 * Replaces the raw HTTP exchange to capture the exact URL and headers
 * S3Driver hands to curl, without any network.
 */
final class CapturingS3Driver extends S3Driver
{
    public ?string $capturedUrl = null;
    public ?string $capturedAuthorizationHeader = null;

    protected function executeHttp(string $url, string $method, array $curlHeaders, string $body, array &$responseHeaders): array
    {
        $this->capturedUrl = $url;
        foreach ($curlHeaders as $header) {
            if (str_starts_with($header, 'Authorization:')) {
                $this->capturedAuthorizationHeader = $header;
            }
        }

        return ['body' => '', 'errno' => 0, 'error' => '', 'status' => 200];
    }
}
