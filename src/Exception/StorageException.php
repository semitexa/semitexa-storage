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

    /**
     * A remote store answered, and the answer was neither success nor a plain
     * "not there". Carries the operation and the status so a caller can tell an
     * outage from a deletion and decide whether retrying makes sense.
     *
     * The response BODY is deliberately not included: on S3 it carries bucket
     * policy detail, request ids and occasionally signed material, and this
     * message reaches logs.
     */
    public static function requestFailed(string $driver, string $method, string $path, int $status): self
    {
        return new self("{$driver} {$method} '{$path}' failed with HTTP {$status}");
    }
}
