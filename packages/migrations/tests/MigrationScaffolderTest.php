<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\Migration;
use Kinetis\Migrations\MigrationScaffolder;
use PHPUnit\Framework\TestCase;

final class MigrationScaffolderTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-scaffolder-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->migrationsPath)) {
            foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->migrationsPath);
        }
    }

    public function test_create_creates_the_migrations_directory_when_missing(): void
    {
        self::assertDirectoryDoesNotExist($this->migrationsPath);

        MigrationScaffolder::create($this->migrationsPath, 'create orders table');

        self::assertDirectoryExists($this->migrationsPath);
    }

    public function test_create_names_the_file_with_a_timestamp_prefix_and_a_slugified_description(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, 'Create Orders Table!!');

        self::assertMatchesRegularExpression(
            '/^\d{14}_create_orders_table\.php$/',
            basename($path),
        );
    }

    public function test_create_writes_a_file_that_returns_a_working_migration(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, 'anything');

        /** @var Migration $migration */
        $migration = require $path;

        self::assertInstanceOf(Migration::class, $migration);
    }

    public function test_an_empty_description_falls_back_to_a_generic_name(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, '!!!');

        self::assertMatchesRegularExpression('/^\d{14}_migration\.php$/', basename($path));
    }
}
