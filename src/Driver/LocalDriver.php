<?php

declare(strict_types=1);

namespace Semitexa\Storage\Driver;

use Semitexa\Core\Environment;
use Semitexa\Storage\Contract\StorageObjectStoreInterface;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\Value\LegacyMetadataMigrationReport;
use Semitexa\Storage\Value\StoredObjectDescriptor;
use Semitexa\Storage\Value\StoredObjectMetadata;

final class LocalDriver implements StorageObjectStoreInterface
{
    /**
     * Metadata subtree, reserved inside the storage root. It is NOT addressable
     * through the object API: a caller key resolving into it is refused, which
     * is what keeps driver bookkeeping and caller objects from overwriting each
     * other. Metadata for `a/b` lives at `<root>/.meta/a/b.json`.
     */
    private const METADATA_DIR = '.meta';

    /** The filename the pre-move layout used, kept readable but never written. */
    private const LEGACY_SIDECAR_SUFFIX = '.meta.json';

    private const COLLISION_REASON = 'these two keys cannot both carry metadata; another key already occupies ';

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = self::normalize($basePath ?? $this->resolveDefaultBasePath());
    }

    public function put(string $path, string $contents, string $mimeType): void
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        // The `!mkdir && !is_dir` guard tolerates a concurrent writer creating
        // the directory first (mkdir returns false but the dir now exists —
        // not an error), while still failing on a real permission/mount error.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw StorageException::writeFailed($path, "could not create directory {$dir}");
        }
        if (@file_put_contents($fullPath, $contents) === false) {
            throw StorageException::writeFailed($path, 'file_put_contents failed (disk full, permissions, or path is a directory)');
        }
        // The metadata carries only the MIME type, which is re-derivable via
        // finfo on read, so a failure to WRITE it is not data loss and stays
        // best-effort — the object itself is already durably written above. A
        // metadata path that escapes the root is a different matter and throws.
        $this->writeMetadata($path, $mimeType);
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->fullPath($path, forWrite: false);
        if (!file_exists($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return false;
        }
        $result = unlink($fullPath);
        $this->deleteMetadata($path);
        return $result;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path, forWrite: false));
    }

    public function url(string $path): string
    {
        // Local objects have no intrinsic public URL. If the application
        // exposes the storage root (static mount, CDN, or its own serving
        // route), it says so via STORAGE_LOCAL_PUBLIC_URL and gets the full
        // object path appended. Otherwise return '' — an honest "not
        // publicly addressable" that callers already handle — instead of
        // the previous fabricated /api/platform/files/{basename} link,
        // which pointed at a route that does not exist and dropped the
        // directory part of the path.
        $base = Environment::getEnvValue('STORAGE_LOCAL_PUBLIC_URL', '');
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public function stat(string $path): ?StoredObjectMetadata
    {
        $fullPath = $this->fullPath($path, forWrite: false);
        if (!file_exists($fullPath)) {
            return null;
        }

        return new StoredObjectMetadata(
            path: $path,
            exists: true,
            size: (int) filesize($fullPath),
            mimeType: $this->resolveMimeType($path, $fullPath),
            lastModifiedAt: (new \DateTimeImmutable())->setTimestamp((int) filemtime($fullPath)),
            checksum: null,
        );
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        $fullPath = $this->fullPath($path, forWrite: false);
        if (!file_exists($fullPath)) {
            return null;
        }

        $stream = fopen($fullPath, 'rb');
        return $stream !== false ? $stream : null;
    }

    public function describe(string $path): ?StoredObjectDescriptor
    {
        $metadata = $this->stat($path);
        if ($metadata === null) {
            return null;
        }

        return new StoredObjectDescriptor(
            driver: 'local',
            path: $path,
            url: $this->url($path),
            size: $metadata->size,
            mimeType: $metadata->mimeType,
            etag: null,
        );
    }

    /**
     * Move metadata written in the pre-`.meta/` layout into the reserved
     * subtree, so the leftovers stop showing up as objects in the caller's
     * namespace.
     *
     * Dry by default: nothing is touched unless $apply is true, because a
     * legacy sidecar and a caller's own object are the same kind of file and
     * only the operator knows their data. Two conditions must BOTH hold before
     * a file is treated as bookkeeping rather than content:
     *
     *   - it decodes to exactly `{"mimeType": "<string>"}`, the only shape this
     *     driver ever wrote, and
     *   - the object it claims to describe actually exists.
     *
     * Anything else is reported as skipped and left in place. An orphan whose
     * object is gone is skipped too: deleting it would be a guess.
     *
     * Idempotent — a second run finds nothing left to move.
     */
    public function migrateLegacyMetadata(bool $apply = false): LegacyMetadataMigrationReport
    {
        $root = realpath($this->basePath);
        if ($root === false) {
            return new LegacyMetadataMigrationReport(
                applied: $apply,
                moved: [],
                skipped: [],
                alreadyMigrated: 0,
                unreadableRoot: $this->basePath,
            );
        }

        $moved = [];
        $skipped = [];
        $alreadyMigrated = 0;

        foreach ($this->legacySidecars($root) as $sidecar) {
            $objectPath = substr($sidecar, 0, -strlen(self::LEGACY_SIDECAR_SUFFIX));
            // Sliced by the SAME root the walk enumerated. Slicing by the
            // configured basePath instead mangles every key whenever the root
            // is reached through a symlink — the standard current -> releases
            // deploy layout — and when the configured path is the LONGER of
            // the two the key comes out empty, which turned the first sidecar
            // into .meta/.json and deleted every one after it.
            $key = $objectPath === $root ? '' : substr($objectPath, strlen($root) + 1);

            if (!is_file($objectPath)) {
                $skipped[$sidecar] = 'no object of that name — an orphan, or a caller object in its own right';
                continue;
            }

            if ($this->readMimeTypeFrom($sidecar, strict: true) === null) {
                $skipped[$sidecar] = 'not the shape this driver wrote — treated as a caller object';
                continue;
            }

            // Through confine(), exactly as an ordinary metadata write goes.
            // Built by hand, a symlink planted at .meta/ or below redirects an
            // operator-run --apply into any writable directory, because rename()
            // follows it.
            try {
                $target = $this->metadataPath($key);
            } catch (StorageException $e) {
                $skipped[$sidecar] = 'metadata destination refused: ' . $e->getMessage();
                continue;
            }

            // is_file() alone was too weak: an empty or partial metadata file
            // from an earlier best-effort write counted as "already migrated",
            // and --apply then deleted the VALID legacy copy, leaving stat()
            // with nothing but finfo. The target only counts if it parses as
            // the shape this driver writes.
            if (is_file($target)) {
                if ($this->readMimeTypeFrom($target) !== null) {
                    $alreadyMigrated++;
                    if ($apply) {
                        @unlink($sidecar);
                    }
                    continue;
                }

                if (!$apply) {
                    $moved[] = $key;
                    continue;
                }

                // Unusable. The legacy copy is the good one, so it replaces it.
                @unlink($target);
            }

            if (!$apply) {
                $moved[] = $key;
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $skipped[$sidecar] = "could not create {$dir}";
                continue;
            }

            if (!@rename($sidecar, $target)) {
                $skipped[$sidecar] = 'rename failed';
                continue;
            }

            $moved[] = $key;
        }

        return new LegacyMetadataMigrationReport(
            applied: $apply,
            moved: $moved,
            skipped: $skipped,
            alreadyMigrated: $alreadyMigrated,
        );
    }

    /**
     * Every `*.meta.json` under the root, except inside the reserved subtree.
     *
     * @return list<string>
     */
    private function legacySidecars(string $root): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $reserved = $root . '/' . self::METADATA_DIR;
        foreach ($iterator as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
                continue;
            }
            if (str_ends_with($path, self::LEGACY_SIDECAR_SUFFIX)) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function fullPath(string $path, bool $forWrite = true): string
    {
        // Confine to the storage root. Callers pass server-generated keys today,
        // but the driver must not trust its caller: a `$path` containing `../`
        // would otherwise traverse out of basePath (read/write/delete anywhere).
        // The object may not exist yet (put creates it), so we normalise `..`/`.`
        // lexically rather than realpath-ing the leaf, then require the result to
        // stay under basePath (the trailing `/` in the prefix check rejects
        // sibling directories like `<base>-evil`).
        $full = self::normalize($this->basePath . '/' . $path);
        if ($full !== $this->basePath && !str_starts_with($full, $this->basePath . '/')) {
            throw StorageException::writeFailed($path, 'resolved path escapes the storage root');
        }

        // The metadata subtree is the driver's own. Refusing it here — after
        // normalisation, so `./.meta/x` and `a/../.meta/x` are caught too —
        // is what makes the separation real rather than conventional. Loudly,
        // because a caller whose key is refused needs to know why.
        $relative = $this->relativeKey($full);
        if ($relative === self::METADATA_DIR || str_starts_with($relative, self::METADATA_DIR . '/')) {
            throw StorageException::writeFailed(
                $path,
                sprintf("'%s/' is reserved for object metadata and cannot be used as a key", self::METADATA_DIR)
            );
        }

        return $this->confine($full, $path, $forWrite);
    }

    /**
     * Require the path to resolve inside the canonical root, not merely to spell
     * itself that way. The lexical check above collapses `..` in the STRING; it
     * says nothing about a symlink already sitting inside the root, through
     * which a perfectly innocent-looking key lands somewhere else entirely.
     *
     * RESIDUAL RISK, stated rather than papered over: realpath() answers about
     * the filesystem as it was a moment ago. Between this check and the open
     * that follows, a writer with access to the storage root can swap a
     * directory for a link — the classic TOCTOU. Closing that needs openat()
     * with O_NOFOLLOW on a directory descriptor, which PHP does not expose. So
     * this raises the cost of an attack that already requires filesystem
     * access; it is not a boundary to lean on.
     */
    private function confine(string $full, string $key, bool $forWrite = true): string
    {
        $root = realpath($this->basePath);
        if ($root === false) {
            // The root does not exist yet, so there is nothing inside it to
            // walk through. The lexical check is all there is to enforce.
            return $full;
        }

        // A link AT the leaf must be resolved before anything opens it, and a
        // DANGLING one refused: writing through a dangling link creates its
        // target, wherever that points.
        if (is_link($full)) {
            $target = realpath($full);

            if ($target === false) {
                // Dangling. Writing through it CREATES its target, wherever
                // that points, so a write is refused — but a READ of it finds
                // nothing either way, and turning a miss into an exception
                // would make one broken link in the root a 500 across every
                // caller that treats absence as normal.
                if ($forWrite) {
                    throw StorageException::writeFailed($key, 'resolved path is a dangling symbolic link');
                }

                return $full;
            }

            if (!self::isInside($target, $root)) {
                throw StorageException::writeFailed($key, 'resolved path escapes the storage root');
            }

            return $full;
        }

        // Otherwise resolve the deepest part that exists. realpath() collapses
        // every symlink in that prefix, so one canonical answer covers the whole
        // ancestry — no need to walk it link by link.
        $existing = $full;
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        $real = realpath($existing);
        if ($real === false || !self::isInside($real, $root)) {
            throw StorageException::writeFailed($key, 'resolved path escapes the storage root');
        }

        return $full;
    }

    private static function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** The caller-visible key a resolved absolute path corresponds to. */
    private function relativeKey(string $fullPath): string
    {
        if ($fullPath === $this->basePath) {
            return '';
        }

        return substr($fullPath, strlen($this->basePath) + 1);
    }

    /**
     * Collapse `.`/`..`/empty path segments. Lexical (no symlink/realpath), so it
     * works on a not-yet-existing target; preserves a leading `/`.
     */
    private static function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $parts);
    }

    /**
     * Where the metadata for a caller key lives. Inside the reserved subtree,
     * mirroring the object's own path, so nested keys stay nested and two
     * objects can never share one metadata file.
     */
    private function metadataPath(string $path): string
    {
        $relative = $this->relativeKey($this->fullPath($path));
        $metadataPath = $this->basePath . '/' . self::METADATA_DIR . '/' . $relative . '.json';

        // The reserved subtree is the driver's own, which is exactly why a link
        // planted inside it must not be followed. Unlike an ordinary metadata
        // write failure this is refused loudly: it means someone has been
        // rearranging the storage root, and carrying on quietly is worse than
        // failing the put.
        return $this->confine($metadataPath, $path);
    }

    private function writeMetadata(string $path, string $mimeType): void
    {
        $metadataPath = $this->metadataPath($path);
        $dir = dirname($metadataPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // Two shapes of failure, and only one of them is a hiccup.
            //
            // Metadata for key `report` is a FILE at .meta/report.json, and
            // metadata for a key under `report.json/` needs that same path to
            // be a DIRECTORY. One of the two always loses, and losing quietly
            // means stat() drops the caller's MIME type with nothing said —
            // the silent-overwrite shape this whole layout exists to remove.
            // So a structural conflict is named, while a full disk or a
            // read-only mount stays best-effort as the docblock promises.
            if (self::hasFileAncestor($dir)) {
                throw StorageException::writeFailed($path, self::COLLISION_REASON . $dir);
            }

            return;
        }

        // The same conflict, arrived at from the other side: a key under
        // `report.json/` was stored FIRST, so .meta/report.json is already a
        // directory and the metadata for `report` cannot be a file there. The
        // mkdir branch above never runs, because its parent exists.
        if (is_dir($metadataPath)) {
            throw StorageException::writeFailed($path, self::COLLISION_REASON . $metadataPath);
        }
        $data = json_encode(['mimeType' => $mimeType], JSON_THROW_ON_ERROR);
        @file_put_contents($metadataPath, $data);
    }

    /** Is some ancestor of $dir an existing file, rather than a directory? */
    private static function hasFileAncestor(string $dir): bool
    {
        $probe = $dir;
        while ($probe !== '' && $probe !== '/' && $probe !== dirname($probe)) {
            if (is_file($probe)) {
                return true;
            }
            $probe = dirname($probe);
        }

        return false;
    }

    private function deleteMetadata(string $path): void
    {
        $metadataPath = $this->metadataPath($path);
        if (is_file($metadataPath)) {
            @unlink($metadataPath);
        }

        // A legacy `<key>.meta.json` is deliberately left alone. After this
        // change it is an ordinary object in the caller's namespace, and
        // deleting it here would be the very bug this layout removes — one
        // object's delete reaching into another's. Leftovers from the old
        // layout stay visible until an operator clears them.
    }

    private function readStoredMimeType(string $path): ?string
    {
        $mime = $this->readMimeTypeFrom($this->metadataPath($path));
        if ($mime !== null) {
            return $mime;
        }

        // Objects written before the move still have their metadata beside
        // them. Read it, but only when it has the exact shape this driver used
        // to write: one key, a string value. Anything richer is a caller's own
        // JSON object that merely shares the name, and must not be read as
        // bookkeeping.
        return $this->readMimeTypeFrom($this->fullPath($path) . self::LEGACY_SIDECAR_SUFFIX, strict: true);
    }

    private function readMimeTypeFrom(string $file, bool $strict = false): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        if ($strict && array_keys($data) !== ['mimeType']) {
            return null;
        }

        $mime = $data['mimeType'] ?? null;

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function resolveMimeType(string $path, string $fullPath): string
    {
        $stored = $this->readStoredMimeType($path);
        if ($stored !== null) {
            return $stored;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($fullPath);
        return $detected !== false ? $detected : 'application/octet-stream';
    }

    private function resolveDefaultBasePath(): string
    {
        $projectRoot = Environment::getEnvValue('PROJECT_ROOT', getcwd());
        return $projectRoot . '/var/uploads';
    }
}
