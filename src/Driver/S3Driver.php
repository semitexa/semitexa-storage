<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;

class S3Driver implements StorageObjectStoreInterface
{
    private readonly string $bucket;
    private readonly string $region;
    private readonly string $endpoint;
    private readonly string $key;
    private readonly string $secret;

    public function __construct(
        ?string $bucket = null,
        ?string $region = null,
        ?string $endpoint = null,
        ?string $key = null,
        ?string $secret = null,
    ) {
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
        $response = $this->request('DELETE', $path);
        // S3 answers 204 whether or not the object was there, so a 2xx is a
        // completed delete. A 404 is the same outcome stated differently.
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
     * Only 404 is absence. A 403, a 429 and a 5xx are the store telling us it
     * could not answer, and reporting those as "not found" is how an outage or
     * a revoked credential comes to look like a deleted file — the caller stops
     * retrying, and goes looking for who removed the object.
     */
    private function isPresent(int $status, string $method, string $path): bool
    {
        if ($status >= HttpStatus::Ok->value && $status < HttpStatus::MultipleChoices->value) {
            return true;
        }

        if ($status === HttpStatus::NotFound->value) {
            return false;
        }

        throw StorageException::requestFailed('S3', $method, $path, $status);
    }

    public function url(string $path): string
    {
        return rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . ltrim($path, '/');
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
        $path = '/' . ltrim($path, '/');
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
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        });

        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $result = [
            'body' => $responseBody,
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
        curl_close($ch);

        return $result;
    }
}
