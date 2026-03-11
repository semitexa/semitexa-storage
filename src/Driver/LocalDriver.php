<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Storage\Contract\StorageDriverInterface;

final class LocalDriver implements StorageDriverInterface
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? $this->resolveDefaultBasePath(), '/');
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        $fullPath = $this->basePath . '/' . $path;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, $contents);
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->basePath . '/' . $path;
        if (!file_exists($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->basePath . '/' . $path;
        if (!file_exists($fullPath)) {
            return false;
        }
        return unlink($fullPath);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->basePath . '/' . $path);
    }

    public function url(string $path): string
    {
        return '/api/platform/files/' . pathinfo($path, PATHINFO_FILENAME);
    }

    private function resolveDefaultBasePath(): string
    {
        $projectRoot = Environment::getEnvValue('PROJECT_ROOT') ?? getcwd();
        return $projectRoot . '/var/uploads';
    }
}
