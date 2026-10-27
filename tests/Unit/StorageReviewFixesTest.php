<?php

declare(strict_types=1);

namespace Semitexa\Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Storage\Driver\LocalDriver;
use Semitexa\Storage\Application\Service\StorageDriverDoctorCheck;
use Semitexa\Storage\Exception\StorageException;
use Semitexa\Storage\StorageManager;

/** Found while writing the storage guide (2026-10-21). */
final class StorageReviewFixesTest extends TestCase
{
    private string $base;
    private ?string $previousDriver = null;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-storage-fixes-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/reports/2026', 0777, true);
        $this->previousDriver = getenv('STORAGE_DRIVER') !== false ? (string) getenv('STORAGE_DRIVER') : null;
    }

    protected function tearDown(): void
    {
        @rmdir($this->base . '/reports/2026');
        @rmdir($this->base . '/reports');
        @rmdir($this->base);
        $this->previousDriver === null ? putenv('STORAGE_DRIVER') : putenv('STORAGE_DRIVER=' . $this->previousDriver);
    }

    #[Test]
    public function a_directory_is_not_an_object(): void
    {
        $driver = new LocalDriver($this->base);

        self::assertFalse($driver->exists('reports/2026'));
        self::assertNull($driver->get('reports/2026'));
        self::assertNull($driver->stat('reports/2026'));
        self::assertNull($driver->readStream('reports/2026'));
        self::assertFalse($driver->delete('reports/2026'));
        self::assertDirectoryExists($this->base . '/reports/2026');
    }

    #[Test]
    public function an_unknown_storage_driver_is_refused_rather_than_quietly_local(): void
    {
        putenv('STORAGE_DRIVER=s4');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('STORAGE_DRIVER=s4');
        (new StorageManager())->exists('anything');
    }

    #[Test]
    public function the_driver_name_is_read_without_case_or_spaces(): void
    {
        putenv('STORAGE_DRIVER= LOCAL ');

        self::assertSame('local', StorageManager::configuredDriver());
        self::assertFalse((new StorageManager())->exists('surely-not-there-' . bin2hex(random_bytes(4))));
    }

    #[Test]
    public function system_doctor_names_an_unknown_driver_before_traffic_does(): void
    {
        self::assertSame('fail', StorageDriverDoctorCheck::verdict('s4')->status->value);
        self::assertSame('pass', StorageDriverDoctorCheck::verdict('s3')->status->value);
        self::assertSame('pass', StorageDriverDoctorCheck::verdict('local')->status->value);
    }

    #[Test]
    public function the_local_root_is_the_project_not_the_working_directory(): void
    {
        $previousRoot = getenv('PROJECT_ROOT');
        $previousCwd = (string) getcwd();
        putenv('PROJECT_ROOT');
        chdir($this->base);
        try {
            $driver = new LocalDriver();
            $root = (new \ReflectionProperty(LocalDriver::class, 'basePath'))->getValue($driver);
        } finally {
            chdir($previousCwd);
            $previousRoot === false ? putenv('PROJECT_ROOT') : putenv('PROJECT_ROOT=' . $previousRoot);
        }

        self::assertStringStartsWith(\Semitexa\Core\Support\ProjectRoot::get(), (string) $root);
        self::assertStringNotContainsString($this->base, (string) $root, 'a script run from another directory wrote there');
    }
}
