<?php

declare(strict_types=1);

namespace Semitexa\Storage\Value;

final readonly class StoredObjectDescriptor
{
    public function __construct(
        public string $driver,
        public string $path,
        public ?string $url,
        public ?int $size,
        public ?string $mimeType,
        public ?string $etag,
    ) {}
}
