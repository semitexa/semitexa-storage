<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Exception\StorageException;

/**
 * The driver used to keep an object's MIME type in a sidecar named after the
 * object itself — `<key>.meta.json`. That name is a perfectly ordinary key, so
 * storing an object AT that key and storing the object it shadows destroyed
 * each other: writing `report` overwrote the caller's `report.meta.json`, and
 * deleting `report` deleted it too.
 *
 * Metadata now lives in a reserved `.meta/` subtree that the object API cannot
 * address, so no caller key can collide with it.
 */
final class LocalDriverMetadataIsolationTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-storage-meta-' . uniqid('', true);
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
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
    public function an_object_named_like_a_sidecar_survives_a_write_to_the_object_it_shadows(): void
    {
        $driver = new LocalDriver($this->base);

        $driver->put('report', 'first', 'text/plain');
        $driver->put('report.meta.json', 'independent-object', 'application/json');
        $driver->put('report', 'second', 'text/plain');

        self::assertSame('independent-object', $driver->get('report.meta.json'));
        self::assertSame('second', $driver->get('report'));
    }

    #[Test]
    public function deleting_an_object_does_not_delete_the_object_named_like_its_sidecar(): void
    {
        $driver = new LocalDriver($this->base);

        $driver->put('report', 'body', 'text/plain');
        $driver->put('report.meta.json', 'independent-object', 'application/json');

        self::assertTrue($driver->delete('report'));

        self::assertTrue($driver->exists('report.meta.json'));
        self::assertSame('independent-object', $driver->get('report.meta.json'));
    }

    #[Test]
    public function metadata_does_not_appear_in_the_object_namespace(): void
    {
        $driver = new LocalDriver($this->base);
        $driver->put('nested/dir/report', 'body', 'text/csv');

        self::assertFalse($driver->exists('nested/dir/report.meta.json'));
        self::assertNull($driver->get('nested/dir/report.meta.json'));
    }

    #[Test]
    public function the_caller_supplied_mime_type_still_round_trips(): void
    {
        $driver = new LocalDriver($this->base);

        // Bytes that finfo would call text/plain; only the caller knows better.
        $driver->put('data/rows', "a,b,c\n1,2,3\n", 'text/csv');

        self::assertSame('text/csv', $driver->stat('data/rows')?->mimeType);
        self::assertSame('text/csv', $driver->describe('data/rows')?->mimeType);
    }

    #[Test]
    public function an_overwrite_updates_the_stored_mime_type(): void
    {
        $driver = new LocalDriver($this->base);

        $driver->put('thing', 'x', 'text/csv');
        $driver->put('thing', 'x', 'application/json');

        self::assertSame('application/json', $driver->stat('thing')?->mimeType);
    }

    #[Test]
    public function deleting_an_object_removes_its_metadata(): void
    {
        $driver = new LocalDriver($this->base);
        $driver->put('thing', 'x', 'text/csv');
        $driver->delete('thing');

        self::assertFalse(is_file($this->base . '/.meta/thing.json'));
    }

    #[Test]
    public function the_reserved_metadata_prefix_is_refused_loudly(): void
    {
        $driver = new LocalDriver($this->base);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/reserved/i');
        $driver->put('.meta/anything', 'x', 'text/plain');
    }

    #[Test]
    public function the_reserved_prefix_is_refused_however_it_is_spelled(): void
    {
        $driver = new LocalDriver($this->base);

        foreach (['.meta', './.meta/x', 'a/../.meta/x'] as $key) {
            try {
                $driver->exists($key);
                self::fail("'{$key}' resolved into the reserved metadata subtree and was not refused");
            } catch (StorageException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function a_key_that_merely_starts_with_the_reserved_name_is_allowed(): void
    {
        $driver = new LocalDriver($this->base);

        $driver->put('.metadata-of-mine', 'x', 'text/plain');
        self::assertSame('x', $driver->get('.metadata-of-mine'));
    }

    #[Test]
    public function a_legacy_sidecar_still_answers_for_an_object_written_before_the_move(): void
    {
        $driver = new LocalDriver($this->base);
        $driver->put('legacy', "a,b\n", 'text/plain');

        // Simulate the pre-move layout: metadata beside the object, none in .meta/.
        unlink($this->base . '/.meta/legacy.json');
        file_put_contents($this->base . '/legacy.meta.json', json_encode(['mimeType' => 'text/csv']));

        self::assertSame('text/csv', $driver->stat('legacy')?->mimeType);
    }

    #[Test]
    public function a_callers_json_object_is_never_mistaken_for_legacy_metadata(): void
    {
        $driver = new LocalDriver($this->base);
        $driver->put('doc', 'body', 'text/plain');
        unlink($this->base . '/.meta/doc.json');

        // A real object of the caller's, which happens to be JSON carrying more
        // than the one key the driver ever wrote.
        file_put_contents(
            $this->base . '/doc.meta.json',
            json_encode(['mimeType' => 'text/csv', 'author' => 'someone'])
        );

        self::assertNotSame('text/csv', $driver->stat('doc')?->mimeType,
            'only the exact shape the driver used to write may be read back as metadata');
    }
}
