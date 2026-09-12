<?php

declare(strict_types=1);

namespace Semitexa\Storage\Value;

/**
 * What a legacy-metadata migration did, or would do.
 *
 * Counts alone would not be enough to act on: an operator deciding whether to
 * run the thing for real needs to see WHICH files were left behind and why, so
 * the skipped ones are named.
 */
final readonly class LegacyMetadataMigrationReport
{
    /**
     * @param list<string>                $moved   object keys whose metadata moved into the reserved subtree
     * @param array<string, string>       $skipped legacy file path => why it was left alone
     */
    public function __construct(
        public bool $applied,
        public array $moved,
        public array $skipped,
        public int $alreadyMigrated,
    ) {}

    public function movedCount(): int
    {
        return count($this->moved);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function isEmpty(): bool
    {
        return $this->moved === [] && $this->skipped === [] && $this->alreadyMigrated === 0;
    }
}
