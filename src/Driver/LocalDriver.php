<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;

final class LocalDriver implements StorageObjectStoreInterface
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = self::normalize($basePath ?? $this->resolveDefaultBasePath());
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        // The `!mkdir && !is_dir` guard tolerates a concurrent writer creating
        // the directory first (mkdir returns false but the dir now exists —
        // not an error), while still failing on a real permission/mount error.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw StorageException::writeFailed($path, "could not create directory {$dir}");
        }
        if (@file_put_contents($fullPath, $contents) === false) {
            throw StorageException::writeFailed($path, 'file_put_contents failed (disk full, permissions, or path is a directory)');
        }
        // The sidecar carries only the MIME type, which is re-derivable via
        // finfo on read, so a sidecar write failure is not data loss and stays
        // best-effort — the object itself is already durably written above.
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
        // Local objects have no intrinsic public URL. If the application
        // exposes the storage root (static mount, CDN, or its own serving
        // route), it says so via STORAGE_LOCAL_PUBLIC_URL and gets the full
        // object path appended. Otherwise return '' — an honest "not
        // publicly addressable" that callers already handle — instead of
        // the previous fabricated /api/platform/files/{basename} link,
        // which pointed at a route that does not exist and dropped the
        // directory part of the path.
        $base = Environment::getEnvValue('STORAGE_LOCAL_PUBLIC_URL', '');
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
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
        // Confine to the storage root. Callers pass server-generated keys today,
        // but the driver must not trust its caller: a `$path` containing `../`
        // would otherwise traverse out of basePath (read/write/delete anywhere).
        // The object may not exist yet (put creates it), so we normalise `..`/`.`
        // lexically rather than realpath-ing the leaf, then require the result to
        // stay under basePath (the trailing `/` in the prefix check rejects
        // sibling directories like `<base>-evil`).
        $full = self::normalize($this->basePath . '/' . $path);
        if ($full !== $this->basePath && !str_starts_with($full, $this->basePath . '/')) {
            throw StorageException::writeFailed($path, 'resolved path escapes the storage root');
        }

        return $full;
    }

    /**
     * Collapse `.`/`..`/empty path segments. Lexical (no symlink/realpath), so it
     * works on a not-yet-existing target; preserves a leading `/`.
     */
    private static function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $parts);
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
