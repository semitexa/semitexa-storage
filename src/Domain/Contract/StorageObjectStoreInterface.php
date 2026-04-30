<?php

declare(strict_types=1);

namespace Semitexa\Storage\Domain\Contract;

use Semitexa\Storage\Domain\Model\StoredObjectDescriptor;
use Semitexa\Storage\Domain\Model\StoredObjectMetadata;

interface StorageObjectStoreInterface extends StorageDriverInterface
{
    /**
     * Returns metadata for the stored object, or null when the object does not exist.
     */
    public function stat(string $path): ?StoredObjectMetadata;

    /**
     * Returns a readable stream resource, or null when the object does not exist.
     *
     * Callers are responsible for closing the returned resource.
     *
     * @return resource|null
     */
    public function readStream(string $path);

    /**
     * Returns a serializable descriptor for the stored object, or null when the object does not exist.
     */
    public function describe(string $path): ?StoredObjectDescriptor;
}
