<?php

declare(strict_types=1);

namespace Semitexa\Storage;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Storage\Contract\StorageDriverInterface;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;

#[SatisfiesServiceContract(of: StorageDriverInterface::class)]
#[SatisfiesServiceContract(of: StorageObjectStoreInterface::class)]
final class StorageManager implements StorageObjectStoreInterface
{
    private ?StorageObjectStoreInterface $driver = null;

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

    public function stat(string $path): ?StoredObjectMetadata
    {
        return $this->getDriver()->stat($path);
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        return $this->getDriver()->readStream($path);
    }

    public function describe(string $path): ?StoredObjectDescriptor
    {
        return $this->getDriver()->describe($path);
    }

    private function getDriver(): StorageObjectStoreInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = Environment::getEnvValue('STORAGE_DRIVER', 'local');

        $this->driver = match ($driverName) {
            's3' => new S3Driver(),
            default => new LocalDriver(),
        };

        return $this->driver;
    }
}
