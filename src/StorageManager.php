<?php

declare(strict_types=1);

namespace Semitexa\Storage;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Storage\Contract\StorageDriverInterface;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Driver\S3Driver;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Http\Client\HttpClientInterface;

#[SatisfiesServiceContract(of: StorageDriverInterface::class)]
#[SatisfiesServiceContract(of: StorageObjectStoreInterface::class)]
final class StorageManager implements StorageObjectStoreInterface
{
    #[InjectAsReadonly]
    protected HttpClientInterface $http;

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

    /** STORAGE_DRIVER as the manager reads it: trimmed, lowercase, `local` when unset or blank. */
    public static function configuredDriver(): string
    {
        $driver = strtolower(trim((string) Environment::getEnvValue('STORAGE_DRIVER', 'local')));

        // A blank STORAGE_DRIVER= selects the local driver, so it is reported
        // as local: callers that guard on the name must see the same choice.
        return $driver === '' ? 'local' : $driver;
    }

    private function getDriver(): StorageObjectStoreInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driverName = self::configuredDriver();

        // An unknown name used to fall through to local: STORAGE_DRIVER=S3 or a
        // typo wrote every upload to this server's disk while the project
        // believed it was in the bucket.
        $this->driver = match ($driverName) {
            's3' => new S3Driver(http: isset($this->http) ? $this->http : null),
            'local', '' => new LocalDriver(),
            default => throw StorageException::unknownDriver($driverName),
        };

        return $this->driver;
    }
}
