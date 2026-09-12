<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Exception\StorageException;

/**
 * The root check used to be purely lexical: `..` was collapsed in the string
 * and the result had to start with the base path. That confines a caller who
 * spells an escape, but not one who walks through a symlink already sitting
 * inside the root — the string stays under the base while the filesystem
 * resolves elsewhere, so a write landed outside.
 *
 * Reaching that state needs filesystem access outside this API, so this is
 * defence in depth rather than a route from a request. What it must guarantee
 * is that no read, write or delete resolves outside the canonical root.
 *
 * Residual risk is stated where the check lives: realpath answers about the
 * filesystem as it was a moment ago, and PHP has no openat/O_NOFOLLOW to close
 * the gap between the check and the write.
 */
final class LocalDriverSymlinkConfinementTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-storage-link-' . uniqid('', true);
        $this->root = $this->tmp . '/root';
        $this->outside = $this->tmp . '/outside';
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->outside . '/secret.txt', 'control-fixture');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    #[Test]
    public function a_write_through_a_linked_directory_is_refused(): void
    {
        symlink($this->outside, $this->root . '/link');
        $driver = new LocalDriver($this->root);

        try {
            $driver->put('link/probe.txt', 'escaped', 'text/plain');
            self::fail('the write resolved outside the storage root');
        } catch (StorageException $e) {
            self::assertStringContainsString('escapes the storage root', $e->getMessage());
        }

        self::assertFalse(file_exists($this->outside . '/probe.txt'), 'nothing may be created outside the root');
    }

    #[Test]
    public function a_read_through_a_linked_directory_is_refused(): void
    {
        symlink($this->outside, $this->root . '/link');
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->get('link/secret.txt');
    }

    #[Test]
    public function a_linked_object_is_refused(): void
    {
        symlink($this->outside . '/secret.txt', $this->root . '/alias.txt');
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->get('alias.txt');
    }

    #[Test]
    public function deleting_through_a_link_leaves_the_target_alone(): void
    {
        symlink($this->outside . '/secret.txt', $this->root . '/alias.txt');
        $driver = new LocalDriver($this->root);

        try {
            $driver->delete('alias.txt');
        } catch (StorageException) {
            // expected
        }

        self::assertSame('control-fixture', file_get_contents($this->outside . '/secret.txt'));
    }

    #[Test]
    public function a_dangling_link_is_refused_rather_than_followed(): void
    {
        // Nothing to resolve yet: writing through it would CREATE the target.
        symlink($this->outside . '/not-there-yet.txt', $this->root . '/dangling.txt');
        $driver = new LocalDriver($this->root);

        try {
            $driver->put('dangling.txt', 'escaped', 'text/plain');
            self::fail('the write followed a dangling link out of the root');
        } catch (StorageException) {
            // expected
        }

        self::assertFalse(file_exists($this->outside . '/not-there-yet.txt'));
    }

    #[Test]
    public function metadata_cannot_be_written_through_a_linked_metadata_tree(): void
    {
        mkdir($this->root . '/.meta', 0777, true);
        symlink($this->outside, $this->root . '/.meta/nested');
        $driver = new LocalDriver($this->root);

        try {
            $driver->put('nested/report', 'body', 'text/csv');
        } catch (StorageException) {
            // Refusing the whole write is an acceptable answer too.
        }

        self::assertFalse(file_exists($this->outside . '/report.json'),
            'metadata must not be written outside the root either');
    }

    #[Test]
    public function ordinary_nested_writes_still_work(): void
    {
        $driver = new LocalDriver($this->root);

        $driver->put('a/b/c/report.txt', 'body', 'text/plain');

        self::assertSame('body', $driver->get('a/b/c/report.txt'));
        self::assertTrue($driver->exists('a/b/c/report.txt'));
        self::assertSame('text/plain', $driver->stat('a/b/c/report.txt')?->mimeType);
        self::assertTrue($driver->delete('a/b/c/report.txt'));
    }

    #[Test]
    public function a_link_that_stays_inside_the_root_is_still_usable(): void
    {
        mkdir($this->root . '/real', 0777, true);
        symlink($this->root . '/real', $this->root . '/alias');
        $driver = new LocalDriver($this->root);

        $driver->put('alias/thing.txt', 'body', 'text/plain');

        self::assertSame('body', $driver->get('alias/thing.txt'));
        self::assertSame('body', file_get_contents($this->root . '/real/thing.txt'));
    }

    #[Test]
    public function the_lexical_escape_is_still_refused(): void
    {
        $driver = new LocalDriver($this->root);

        $this->expectException(StorageException::class);
        $driver->put('../outside/probe.txt', 'escaped', 'text/plain');
    }
}
