<?php

declare(strict_types=1);

namespace Semitexa\Storage\Value;

final readonly class StoredObjectMetadata
{
    public function __construct(
        public string $path,
        public bool $exists,
        public ?int $size = null,
        public ?string $mimeType = null,
        public ?\DateTimeImmutable $lastModifiedAt = null,
        public ?string $etag = null,
        public ?string $checksum = null,
    ) {}
}
