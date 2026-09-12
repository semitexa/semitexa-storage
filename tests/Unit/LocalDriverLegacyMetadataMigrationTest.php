<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\LocalDriver;

/**
 * Metadata written in the old layout sits beside its object as
 * `<key>.meta.json`, which after the move is an ordinary key in the caller's
 * namespace. The migration must therefore be conservative: move only what is
 * unmistakably this driver's own bookkeeping, name everything it refused, and
 * change nothing at all unless asked.
 */
final class LocalDriverLegacyMetadataMigrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-storage-migrate-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
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

    /** Lay down the pre-move shape: object plus sidecar, nothing in .meta/. */
    private function legacyObject(string $key, string $mime = 'image/png'): void
    {
        $full = $this->root . '/' . $key;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, 'body');
        file_put_contents($full . '.meta.json', json_encode(['mimeType' => $mime]));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->legacyObject('a/b/original.png');
        $driver = new LocalDriver($this->root);

        $report = $driver->migrateLegacyMetadata();

        self::assertFalse($report->applied);
        self::assertSame(['a/b/original.png'], $report->moved);
        self::assertTrue(is_file($this->root . '/a/b/original.png.meta.json'), 'the dry run must not move anything');
        self::assertFalse(is_file($this->root . '/.meta/a/b/original.png.json'));
    }

    #[Test]
    public function applying_moves_the_metadata_and_keeps_the_mime_type_readable(): void
    {
        $this->legacyObject('a/b/original.png', 'image/png');
        $driver = new LocalDriver($this->root);

        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame(['a/b/original.png'], $report->moved);
        self::assertFalse(is_file($this->root . '/a/b/original.png.meta.json'), 'the leftover must be gone');
        self::assertTrue(is_file($this->root . '/.meta/a/b/original.png.json'));
        self::assertSame('image/png', $driver->stat('a/b/original.png')?->mimeType);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->legacyObject('thing.png');
        $driver = new LocalDriver($this->root);

        $driver->migrateLegacyMetadata(apply: true);
        $second = $driver->migrateLegacyMetadata(apply: true);

        self::assertTrue($second->isEmpty(), 'a second run has nothing left to do');
    }

    #[Test]
    public function a_caller_object_that_merely_shares_the_name_is_left_alone(): void
    {
        $driver = new LocalDriver($this->root);
        file_put_contents($this->root . '/report', 'body');
        file_put_contents($this->root . '/report.meta.json', json_encode(['mimeType' => 'text/csv', 'author' => 'someone']));

        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame([], $report->moved);
        self::assertArrayHasKey($this->root . '/report.meta.json', $report->skipped);
        self::assertSame(
            json_encode(['mimeType' => 'text/csv', 'author' => 'someone']),
            file_get_contents($this->root . '/report.meta.json'),
            'a caller object must survive the migration untouched'
        );
    }

    #[Test]
    public function an_orphan_without_its_object_is_reported_not_deleted(): void
    {
        $driver = new LocalDriver($this->root);
        file_put_contents($this->root . '/gone.meta.json', json_encode(['mimeType' => 'image/png']));

        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame([], $report->moved);
        self::assertArrayHasKey($this->root . '/gone.meta.json', $report->skipped);
        self::assertTrue(is_file($this->root . '/gone.meta.json'), 'deleting an orphan would be a guess');
    }

    #[Test]
    public function a_non_json_neighbour_is_left_alone(): void
    {
        $driver = new LocalDriver($this->root);
        file_put_contents($this->root . '/notes', 'body');
        file_put_contents($this->root . '/notes.meta.json', 'not json at all');

        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame([], $report->moved);
        self::assertSame('not json at all', file_get_contents($this->root . '/notes.meta.json'));
    }

    #[Test]
    public function metadata_already_in_the_reserved_subtree_is_not_disturbed(): void
    {
        $driver = new LocalDriver($this->root);
        $driver->put('fresh.png', 'body', 'image/png');
        // A leftover from before, alongside metadata already in the new place.
        file_put_contents($this->root . '/fresh.png.meta.json', json_encode(['mimeType' => 'image/gif']));

        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame(1, $report->alreadyMigrated);
        self::assertSame('image/png', $driver->stat('fresh.png')?->mimeType, 'the current metadata wins');
        self::assertFalse(is_file($this->root . '/fresh.png.meta.json'), 'the stale leftover is removed');
    }

    #[Test]
    public function an_empty_root_reports_nothing_to_do(): void
    {
        $driver = new LocalDriver($this->root);

        self::assertTrue($driver->migrateLegacyMetadata()->isEmpty());
    }
}
