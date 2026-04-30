<?php

declare(strict_types=1);

namespace Semitexa\Storage\Domain\Contract;

interface StorageDriverInterface
{
    public function put(string $path, string $contents, string $mimeType): void;

    public function get(string $path): ?string;

    public function delete(string $path): bool;

    public function exists(string $path): bool;

    public function url(string $path): string;
}
