<?php

declare(strict_types=1);

namespace Semitexa\Storage\Application\Service;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Storage\StorageManager;

/**
 * Whether STORAGE_DRIVER names a driver. An unknown one is refused at the
 * first storage call, which can be a page rendering a media URL; this says it
 * before any traffic does.
 */
#[AsDoctorCheck(name: 'storage.driver', package: 'semitexa/storage')]
final class StorageDriverDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return self::verdict(StorageManager::configuredDriver());
    }

    public static function verdict(string $driver): DoctorResult
    {
        return match ($driver) {
            'local', '' => DoctorResult::pass('Files are stored on this server\'s disk (var/uploads).'),
            's3' => DoctorResult::pass('Files are stored in the S3 bucket.'),
            default => DoctorResult::fail(
                "STORAGE_DRIVER={$driver} is not a storage driver: every storage call fails.",
                'Set STORAGE_DRIVER to local or s3.',
            ),
        };
    }
}
