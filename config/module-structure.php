<?php

declare(strict_types=1);

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

$pascalCasePhp = '/^[A-Z][A-Za-z0-9]*\.php$/';

return new LocalModuleStructureExtension(
    package: 'storage',
    topLevelDirectories: [
        'Contract',
        'Driver',
        'Value',
    ],
    topLevelFiles: [
        'StorageManager.php',
    ],
    pathRules: [
        'Contract' => new ModuleStructureRule(
            path: 'Contract',
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*Interface\.php$/'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-storage public storage contracts imported by other packages.',
        ),
        'Driver' => new ModuleStructureRule(
            path: 'Driver',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-storage public driver implementations.',
        ),
        'Value' => new ModuleStructureRule(
            path: 'Value',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-storage public value objects returned by the storage facade.',
        ),
    ],
    reason: 'semitexa-storage exposes contracts, drivers, value objects, and a facade as package-level public API; these framework primitives must remain importable directly.',
);
