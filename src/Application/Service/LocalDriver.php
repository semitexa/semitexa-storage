<?php

declare(strict_types=1);

namespace Semitexa\Storage\Application\Service;

use Semitexa\Core\Environment;
use Semitexa\Storage\Domain\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Domain\Model\StoredObjectDescriptor;
use Semitexa\Storage\Domain\Model\StoredObjectMetadata;

final class LocalDriver implements StorageObjectStoreInterface
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? $this->resolveDefaultBasePath(), '/');
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, $contents);
        $this->writeSidecarMetadata($fullPath, $mimeType);
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return false;
        }
        $result = unlink($fullPath);
        $this->deleteSidecarMetadata($fullPath);
        return $result;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function url(string $path): string
    {
        return '/api/platform/files/' . pathinfo($path, PATHINFO_FILENAME);
    }

    public function stat(string $path): ?StoredObjectMetadata
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return null;
        }

        return new StoredObjectMetadata(
            path: $path,
            exists: true,
            size: (int) filesize($fullPath),
            mimeType: $this->resolveMimeType($fullPath),
            lastModifiedAt: (new \DateTimeImmutable())->setTimestamp((int) filemtime($fullPath)),
            checksum: null,
        );
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return null;
        }

        $stream = fopen($fullPath, 'rb');
        return $stream !== false ? $stream : null;
    }

    public function describe(string $path): ?StoredObjectDescriptor
    {
        $metadata = $this->stat($path);
        if ($metadata === null) {
            return null;
        }

        return new StoredObjectDescriptor(
            driver: 'local',
            path: $path,
            url: $this->url($path),
            size: $metadata->size,
            mimeType: $metadata->mimeType,
            etag: null,
        );
    }

    private function fullPath(string $path): string
    {
        return $this->basePath . '/' . $path;
    }

    private function sidecarPath(string $fullPath): string
    {
        return $fullPath . '.meta.json';
    }

    private function writeSidecarMetadata(string $fullPath, string $mimeType): void
    {
        $sidecarPath = $this->sidecarPath($fullPath);
        $data = json_encode(['mimeType' => $mimeType], JSON_THROW_ON_ERROR);
        file_put_contents($sidecarPath, $data);
    }

    private function deleteSidecarMetadata(string $fullPath): void
    {
        $sidecarPath = $this->sidecarPath($fullPath);
        if (file_exists($sidecarPath)) {
            unlink($sidecarPath);
        }
    }

    private function readSidecarMimeType(string $fullPath): ?string
    {
        $sidecarPath = $this->sidecarPath($fullPath);
        if (!file_exists($sidecarPath)) {
            return null;
        }

        $contents = file_get_contents($sidecarPath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        return is_array($data) ? ($data['mimeType'] ?? null) : null;
    }

    private function resolveMimeType(string $fullPath): string
    {
        $sidecarMime = $this->readSidecarMimeType($fullPath);
        if ($sidecarMime !== null) {
            return $sidecarMime;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($fullPath);
        return $detected !== false ? $detected : 'application/octet-stream';
    }

    private function resolveDefaultBasePath(): string
    {
        $projectRoot = Environment::getEnvValue('PROJECT_ROOT', getcwd());
        return $projectRoot . '/var/uploads';
    }
}
