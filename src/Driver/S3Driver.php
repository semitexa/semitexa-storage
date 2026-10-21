<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\RetryPolicy;
use Semitexa\Core\Http\Exception\HttpTransportException;

class S3Driver implements StorageObjectStoreInterface
{
    private readonly string $bucket;
    private readonly string $region;
    private readonly string $endpoint;
    private readonly string $key;
    private readonly string $secret;
    private readonly HttpClientInterface $http;

    public function __construct(
        ?string $bucket = null,
        ?string $region = null,
        ?string $endpoint = null,
        ?string $key = null,
        ?string $secret = null,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
        $this->bucket = $bucket ?? Environment::getEnvValue('STORAGE_S3_BUCKET', '');
        $this->region = $region ?? Environment::getEnvValue('STORAGE_S3_REGION', 'us-east-1');
        $this->endpoint = $endpoint ?? Environment::getEnvValue('STORAGE_S3_ENDPOINT', "https://s3.{$this->region}.amazonaws.com");
        $this->key = $key ?? Environment::getEnvValue('STORAGE_S3_KEY', '');
        $this->secret = $secret ?? Environment::getEnvValue('STORAGE_S3_SECRET', '');
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        $response = $this->request('PUT', $path, $contents, [
            'Content-Type' => $mimeType,
        ]);
        $status = $response['status'];
        // Any non-2xx (403 AccessDenied, 500, SignatureDoesNotMatch, or a 0
        // synthesised from a transport failure) means the object never landed.
        // The old code discarded this status and returned as if the put
        // succeeded — silent data loss.
        if ($status < HttpStatus::Ok->value || $status >= HttpStatus::MultipleChoices->value) {
            throw StorageException::writeFailed($path, "S3 PUT returned HTTP {$status}");
        }
    }

    public function get(string $path): ?string
    {
        $response = $this->request('GET', $path);
        return $this->isPresent($response['status'], 'GET', $path) ? $response['body'] : null;
    }

    public function delete(string $path): bool
    {
        // S3 answers 204 whether or not the object was there, so the DELETE
        // alone cannot say whether this call removed something; it reported
        // true for a missing key where LocalDriver says false. Asked first, so
        // the answer means one thing across drivers (two calls race as any
        // check-then-act does; the object is gone either way).
        // Only a definite 404 skips the DELETE: a 403 read as missing under
        // STORAGE_S3_MISSING_IS_FORBIDDEN may hide an object that is there.
        if ($this->request('HEAD', $path)['status'] === HttpStatus::NotFound->value) {
            return false;
        }
        $response = $this->request('DELETE', $path);

        return $this->isPresent($response['status'], 'DELETE', $path);
    }

    public function exists(string $path): bool
    {
        $response = $this->request('HEAD', $path);
        return $this->isPresent($response['status'], 'HEAD', $path);
    }

    /**
     * What a response status means for an object: present, absent, or neither.
     *
     * Only 404 is absence by default. A 403, a 429 and a 5xx are the store
     * telling us it could not answer, and reporting those as "not found" is how
     * an outage or a revoked credential comes to look like a deleted file — the
     * caller stops retrying and goes looking for who removed the object.
     *
     * EXCEPT that S3 answers 403 for a MISSING key when the principal has no
     * `s3:ListBucket` — the common object-level-only policy — so on such a
     * bucket the two are genuinely indistinguishable from the status alone, and
     * strictness turns every ordinary miss into an exception. Deployments in
     * that shape set STORAGE_S3_MISSING_IS_FORBIDDEN=1 and get the old,
     * lenient reading of 403 back. It is opt-in rather than the default because
     * the alternative is silence about real failures; the exception message
     * names the flag so nobody has to find this comment first.
     *
     * That escape applies to GET and HEAD alone. DeleteObject answers 204 for a
     * key that was never there, so nothing about a DELETE 403 is ambiguous.
     */
    private function isPresent(int $status, string $method, string $path): bool
    {
        if ($status >= HttpStatus::Ok->value && $status < HttpStatus::MultipleChoices->value) {
            return true;
        }

        if ($status === HttpStatus::NotFound->value) {
            return false;
        }

        // READS only. DeleteObject answers 204 for a key that is not there, so
        // a 403 on DELETE is a genuine authorization failure and never the
        // ListBucket ambiguity — routing it through this branch would hide a
        // revoked delete permission as an ordinary absent object.
        if ($status === HttpStatus::Forbidden->value
            && in_array($method, ['GET', 'HEAD'], true)
            && self::forbiddenMeansMissing()
        ) {
            return false;
        }

        throw StorageException::requestFailed('S3', $method, $path, $status, self::hintFor($status));
    }

    private static function forbiddenMeansMissing(): bool
    {
        $flag = Environment::getEnvValue('STORAGE_S3_MISSING_IS_FORBIDDEN', '');

        return is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function hintFor(int $status): ?string
    {
        return $status === HttpStatus::Forbidden->value
            ? 'a bucket policy without s3:ListBucket answers 403 for a missing key too; '
                . 'set STORAGE_S3_MISSING_IS_FORBIDDEN=1 to read 403 as absent'
            : null;
    }

    public function url(string $path): string
    {
        return rtrim($this->endpoint, '/') . '/' . $this->bucket . self::encodeObjectPath($path);
    }

    /**
     * Percent-encode an object key for use as a URL path / SigV4 canonical URI.
     *
     * AWS SigV4 requires each path SEGMENT to be URI-encoded per RFC 3986
     * (unreserved chars A-Za-z0-9-_.~ left alone, everything else escaped),
     * while the '/' segment separators themselves stay literal. rawurlencode()
     * matches that RFC 3986 unreserved set exactly, so it is applied per
     * segment rather than to the whole path (which would also escape '/').
     *
     * Without this, a key containing a space or non-ASCII character produced
     * an unescaped URL that curl rejected ("Malformed input") and a public
     * url() link that browsers/clients could not fetch. S3 does NOT
     * double-encode the path for the canonical request (that only applies to
     * the canonical query string), so this single encoding is reused as-is
     * for the request URL, the SigV4 canonical URI, and url() — keeping the
     * signature and the actual request path in agreement.
     */
    private static function encodeObjectPath(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');

        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $normalized),
        ));
    }

    public function stat(string $path): ?StoredObjectMetadata
    {
        $response = $this->requestWithHeaders('HEAD', $path);
        if (!$this->isPresent($response['status'], 'HEAD', $path)) {
            return null;
        }

        $headers = $response['headers'];

        $lastModified = isset($headers['last-modified'])
            ? \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $headers['last-modified']) ?: null
            : null;

        return new StoredObjectMetadata(
            path: $path,
            exists: true,
            size: isset($headers['content-length']) ? (int) $headers['content-length'] : null,
            mimeType: $headers['content-type'] ?? null,
            lastModifiedAt: $lastModified,
            etag: isset($headers['etag']) ? trim($headers['etag'], '"') : null,
        );
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        $response = $this->request('GET', $path);
        if (!$this->isPresent($response['status'], 'GET', $path)) {
            return null;
        }

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return null;
        }

        fwrite($stream, $response['body']);
        rewind($stream);
        return $stream;
    }

    public function describe(string $path): ?StoredObjectDescriptor
    {
        $metadata = $this->stat($path);
        if ($metadata === null) {
            return null;
        }

        return new StoredObjectDescriptor(
            driver: 's3',
            path: $path,
            url: $this->url($path),
            size: $metadata->size,
            mimeType: $metadata->mimeType,
            etag: $metadata->etag,
        );
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, string $body = '', array $extraHeaders = []): array
    {
        $result = $this->requestWithHeaders($method, $path, $body, $extraHeaders);
        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    protected function requestWithHeaders(string $method, string $path, string $body = '', array $extraHeaders = []): array
    {
        // Encoded once and reused for the request URL below AND the canonical
        // request's URI (see encodeObjectPath()) so the signature matches what
        // is actually sent.
        $path = self::encodeObjectPath($path);
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($this->endpoint, PHP_URL_PORT);

        $hostHeader = $this->bucket . '.' . $host;
        if ($port && $port !== 443 && $port !== 80) {
            $hostHeader .= ':' . $port;
        }

        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = array_merge([
            'Host' => $hostHeader,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $datetime,
        ], $extraHeaders);

        if ($body !== '' && isset($extraHeaders['Content-Type'])) {
            $headers['Content-Type'] = $extraHeaders['Content-Type'];
        }

        // Canonical request
        ksort($headers);
        $signedHeaderKeys = [];
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            $signedHeaderKeys[] = $lk;
            $canonicalHeaders .= $lk . ':' . trim($v) . "\n";
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '', // query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $date, 'AWS4' . $this->secret, true),
                    true),
                true),
            true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $scheme . '://' . $hostHeader . $path;

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $responseHeaders = [];
        $transport = $this->executeHttp($url, $method, $curlHeaders, $body, $responseHeaders);

        // A transport failure (DNS, TLS, connection refused, timeout) leaves
        // curl_exec() === false and status 0. Surfacing it is essential: on a
        // read it is otherwise indistinguishable from a legitimate 404, and on
        // a write it silently drops the object. A real HTTP status (incl. 404)
        // is NOT a transport failure and flows through untouched.
        if ($transport['body'] === false || $transport['errno'] !== 0) {
            throw StorageException::transportFailed(
                $method,
                $path,
                $transport['error'] !== '' ? $transport['error'] : "curl error {$transport['errno']}",
            );
        }

        return [
            'status' => $transport['status'],
            'body' => is_string($transport['body']) ? $transport['body'] : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * The raw HTTP exchange, isolated so the signed-request assembly above stays
     * transport-agnostic and testable. Populates $responseHeaders by reference.
     *
     * @param list<string>                $curlHeaders
     * @param array<string, string>       $responseHeaders
     * @return array{body: string|false, errno: int, error: string, status: int}
     */
    protected function executeHttp(string $url, string $method, array $curlHeaders, string $body, array &$responseHeaders): array
    {
        $headers = [];
        foreach ($curlHeaders as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[$name] = [$value];
        }

        try {
            $request = new HttpRequest($method, $url, $headers, $body);
        } catch (\InvalidArgumentException $e) {
            // A header value with a line break (a client-supplied MIME type):
            // a storage failure for the caller, not an InvalidArgumentException.
            return ['body' => false, 'errno' => -1, 'error' => $e->getMessage(), 'status' => 0];
        }

        try {
            // A signed request stays valid for 15 minutes, so resending one is
            // safe; a repeated PUT writes the same object again. An object comes
            // back as stored: no size cap (the driver never had one) and no
            // inflating of an object kept with its own Content-Encoding.
            $response = $this->http->send($request, new HttpCallOptions(
                timeout: 30.0,
                maxResponseBytes: PHP_INT_MAX,
                decodeContent: false,
                retry: RetryPolicy::idempotent(2),
            ));
        } catch (HttpTransportException $e) {
            return ['body' => false, 'errno' => -1, 'error' => $e->detail, 'status' => 0];
        }

        foreach ($response->headers as $name => $values) {
            $responseHeaders[$name] = $values[count($values) - 1];
        }

        return ['body' => $response->body, 'errno' => 0, 'error' => '', 'status' => $response->status];
    }
}
