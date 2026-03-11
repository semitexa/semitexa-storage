<?php

declare(strict_types=1);

namespace Semitexa\Storage;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Storage\Contract\StorageDriverInterface;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Driver\S3Driver;

#[SatisfiesServiceContract(of: StorageDriverInterface::class)]
final class StorageManager implements StorageDriverInterface
{
    private ?StorageDriverInterface $driver = null;

    public function put(string $path, string $contents, string $mimeType): void
    {
        $this->getDriver()->put($path, $contents, $mimeType);
    }

    public function get(string $path): ?string
    {
        return $this->getDriver()->get($path);
    }

    public function delete(string $path): bool
    {
        return $this->getDriver()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->getDriver()->exists($path);
    }

    public function url(string $path): string
    {
        return $this->getDriver()->url($path);
    }

    private function getDriver(): StorageDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = getenv('STORAGE_DRIVER') ?: 'local';

        $this->driver = match ($driverName) {
            's3' => new S3Driver(),
            default => new LocalDriver(),
        };

        return $this->driver;
    }
}
