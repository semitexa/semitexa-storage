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

    /**
     * A storage root reached through a symlink is the normal deploy layout
     * (current -> releases/<ts>), and the migration enumerated canonical paths
     * while slicing keys by the configured one.
     *
     * MEASURED before the fix, with root <tmp>/current/uploads: key
     * 'docs/a.pdf' migrated as 'loads/docs/a.pdf', the metadata landed where
     * the read path never looks, and stat() fell back from application/pdf to
     * text/plain. With the configured path the LONGER of the two the key came
     * out empty, which renamed the first sidecar to .meta/.json and deleted
     * every one after it.
     */
    #[Test]
    public function a_root_reached_through_a_symlink_migrates_to_the_right_keys(): void
    {
        $real = $this->root . '/real';
        $link = $this->root . '/via-link';
        mkdir($real . '/docs', 0777, true);
        symlink($real, $link);

        file_put_contents($real . '/docs/a.pdf', 'BODY');
        file_put_contents($real . '/docs/a.pdf.meta.json', json_encode(['mimeType' => 'application/pdf']));

        $driver = new LocalDriver($link);
        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame(['docs/a.pdf'], $report->moved);
        self::assertFileExists($real . '/.meta/docs/a.pdf.json');
        self::assertSame('application/pdf', $driver->stat('docs/a.pdf')?->mimeType, 'the read path must find what the migration wrote');
    }

    #[Test]
    public function a_dry_run_names_the_leftovers_it_would_remove(): void
    {
        $driver = new LocalDriver($this->root);
        $driver->put('thing.png', 'body', 'image/png');
        // A leftover beside an object whose metadata is already in .meta/.
        file_put_contents($this->root . '/thing.png.meta.json', json_encode(['mimeType' => 'image/gif']));

        $report = $driver->migrateLegacyMetadata();

        self::assertSame(1, $report->alreadyMigrated);
        self::assertFalse($report->applied);
        self::assertTrue(is_file($this->root . '/thing.png.meta.json'), 'a dry run removes nothing');
    }

    /**
     * The migration built its destination by hand and renamed to it. A symlink
     * planted at .meta/ or below therefore redirected an operator-run --apply
     * into any writable directory, because rename() follows it — while an
     * ordinary metadata write went through the same confinement that stops it.
     */
    #[Test]
    public function the_migration_will_not_rename_through_a_planted_symlink(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside, 0777, true);
        $this->legacyObject('report.png');
        mkdir($this->root . '/.meta', 0777, true);
        // Everything under .meta/ now resolves outside the root.
        rmdir($this->root . '/.meta');
        symlink($outside, $this->root . '/.meta');

        try {
            $report = (new LocalDriver($this->root))->migrateLegacyMetadata(apply: true);

            self::assertSame([], $report->moved, 'nothing may be migrated through the link');
            self::assertSame([], glob($outside . '/*') ?: [], 'and nothing may land outside the root');
        } finally {
            @unlink($this->root . '/.meta');
            $this->removeTree($outside);
        }
    }

    /**
     * A caller's own key that happens to be a symlink pointing at something of
     * the legacy shape.
     *
     * is_file() and readMimeTypeFrom() both FOLLOW the link, so it passed for
     * driver metadata — and --apply renamed the LINK into .meta/, which deletes
     * a key the caller can see and, for a relative link, leaves it pointing at
     * nothing from its new depth. This driver has never written a symlink, so
     * being one is enough to disqualify it. Raised in review of storage#20.
     */
    #[Test]
    public function a_symlinked_sidecar_is_the_callers_object_whatever_it_points_at(): void
    {
        // A genuine legacy pair, so the link has something of the right shape
        // to aim at.
        $this->legacyObject('real.png');
        file_put_contents($this->root . '/decoy.png', 'body');
        symlink($this->root . '/real.png.meta.json', $this->root . '/decoy.png.meta.json');

        $report = (new LocalDriver($this->root))->migrateLegacyMetadata(apply: true);

        self::assertTrue(
            is_link($this->root . '/decoy.png.meta.json'),
            'the caller\'s key was moved into the reserved subtree and is gone from where they put it',
        );
        self::assertNotContains('decoy.png', $report->moved);
        self::assertArrayHasKey($this->root . '/decoy.png.meta.json', $report->skipped);
        self::assertStringContainsString('symbolic link', $report->skipped[$this->root . '/decoy.png.meta.json']);

        self::assertContains('real.png', $report->moved, 'the ordinary pair beside it still migrates');
    }

    #[Test]
    public function a_root_that_cannot_be_resolved_is_not_an_empty_one(): void
    {
        $report = (new LocalDriver($this->root . '/does-not-exist'))->migrateLegacyMetadata();

        self::assertTrue($report->isEmpty());
        self::assertNotNull($report->unreadableRoot, 'an empty report needs to say whether anything was read');
    }

    /**
     * A metadata file that does not parse has migrated nothing. Counting it as
     * done deleted the valid legacy copy and left stat() with only finfo.
     */
    #[Test]
    public function an_unusable_target_does_not_count_as_migrated(): void
    {
        $this->legacyObject('thing.png', 'image/png');
        @mkdir($this->root . '/.meta', 0777, true);
        file_put_contents($this->root . '/.meta/thing.png.json', '');

        $driver = new LocalDriver($this->root);
        $report = $driver->migrateLegacyMetadata(apply: true);

        self::assertSame(['thing.png'], $report->moved);
        self::assertSame(0, $report->alreadyMigrated);
        self::assertSame('image/png', $driver->stat('thing.png')?->mimeType, 'the good copy wins');
    }
}
