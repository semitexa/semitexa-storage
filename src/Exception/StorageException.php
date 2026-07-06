<?php

declare(strict_types=1);

namespace Semitexa\Storage\Exception;

/**
 * Raised when a storage operation fails in a way the caller must not mistake
 * for success (a write that never landed) or for a legitimate "not found" (a
 * transport failure that is indistinguishable from a 404 unless surfaced).
 *
 * The drivers previously returned `void`/`null` on these failures, so a full
 * disk, an S3 403, or a DNS/TLS/timeout error looked exactly like a successful
 * put or an absent object. Failing loudly is the point of this type.
 */
final class StorageException extends \RuntimeException
{
    public static function writeFailed(string $path, string $reason): self
    {
        return new self("Storage write failed for '{$path}': {$reason}");
    }

    public static function transportFailed(string $method, string $path, string $reason): self
    {
        return new self("Storage transport failed on {$method} '{$path}': {$reason}");
    }
}
