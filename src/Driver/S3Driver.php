<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Storage\Contract\StorageDriverInterface;

final class S3Driver implements StorageDriverInterface
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
        $this->request('PUT', $path, $contents, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function get(string $path): ?string
    {
        $response = $this->request('GET', $path);
        return $response['status'] === HttpStatus::Ok->value ? $response['body'] : null;
    }

    public function delete(string $path): bool
    {
        $response = $this->request('DELETE', $path);
        return $response['status'] >= HttpStatus::Ok->value && $response['status'] < HttpStatus::MultipleChoices->value;
    }

    public function exists(string $path): bool
    {
        $response = $this->request('HEAD', $path);
        return $response['status'] === HttpStatus::Ok->value;
    }

    public function url(string $path): string
    {
        return rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, string $body = '', array $extraHeaders = []): array
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
    }
}
