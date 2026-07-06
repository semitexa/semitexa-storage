<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Exception\StorageException;

/**
 * LocalDriver::put() used to ignore the return values of mkdir() and
 * file_put_contents(), so a disk-full / read-only-mount / permission failure
 * emitted only a PHP warning while the caller assumed the upload succeeded —
 * silent data loss. It must now throw StorageException when the object cannot
 * be written.
 *
 * The two failure paths are reproduced deterministically and independent of the
 * process uid (so they hold even when tests run as root, where chmod is
 * ignored): a plain FILE occupying the directory slot makes mkdir() fail, and a
 * DIRECTORY occupying the object slot makes file_put_contents() fail.
 */
final class LocalDriverWriteFailureTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-storage-test-' . uniqid('', true);
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    #[Test]
    public function put_writes_the_object_on_the_happy_path(): void
    {
        $driver = new LocalDriver($this->base);
        $driver->put('nested/dir/file.bin', 'payload-bytes', 'application/octet-stream');

        self::assertSame('payload-bytes', $driver->get('nested/dir/file.bin'));
    }

    #[Test]
    public function put_throws_when_the_target_directory_cannot_be_created(): void
    {
        // A plain file occupies the "blocker" name, so mkdir(base/blocker) fails.
        file_put_contents($this->base . '/blocker', 'x');

        $driver = new LocalDriver($this->base);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('could not create directory');
        $driver->put('blocker/file.bin', 'payload', 'application/octet-stream');
    }

    #[Test]
    public function put_throws_when_the_object_content_cannot_be_written(): void
    {
        // A directory occupies the object path, so file_put_contents() fails
        // regardless of permissions (writing to a directory path is an error).
        mkdir($this->base . '/object-as-dir');

        $driver = new LocalDriver($this->base);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('file_put_contents failed');
        $driver->put('object-as-dir', 'payload', 'application/octet-stream');
    }

    #[Test]
    public function a_traversal_path_is_confined_to_the_storage_root(): void
    {
        $driver = new LocalDriver($this->base);
        $marker = dirname($this->base) . '/escaped-' . uniqid('', true) . '.txt';

        foreach (['../' . basename($marker), 'a/../../' . basename($marker), '../../../../etc/passwd'] as $evil) {
            try {
                $driver->put($evil, 'pwned', 'text/plain');
                self::fail("traversal path '{$evil}' was not confined");
            } catch (StorageException $e) {
                self::assertStringContainsString('escapes the storage root', $e->getMessage());
            }
        }

        // A read also refuses to resolve out of the root.
        $this->expectException(StorageException::class);
        $driver->get('../' . basename($marker));
    }

    #[Test]
    public function a_legitimate_nested_key_is_written_under_the_root(): void
    {
        // Confinement must not break normal server-generated keys.
        $driver = new LocalDriver($this->base);
        $driver->put('tenant-1/images/asset-42.bin', 'ok', 'application/octet-stream');

        self::assertSame('ok', $driver->get('tenant-1/images/asset-42.bin'));
        self::assertFileExists($this->base . '/tenant-1/images/asset-42.bin');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
