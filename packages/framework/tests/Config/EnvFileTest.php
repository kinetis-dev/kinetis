<?php

declare(strict_types=1);

namespace Kinetis\Tests\Config;

use Kinetis\Config\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis_env_file_test_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        // glob('/*') doesn't match dotfiles like .env — deleted explicitly
        // rather than widening the glob pattern for just this one file.
        $envFile = $this->directory . '/.env';

        if (file_exists($envFile)) {
            unlink($envFile);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        foreach (['KINETIS_ENV_FILE_TEST_A', 'KINETIS_ENV_FILE_TEST_B'] as $key) {
            putenv($key);
        }
    }

    public function test_loading_a_present_env_file_populates_the_real_environment(): void
    {
        file_put_contents($this->directory . '/.env', "KINETIS_ENV_FILE_TEST_A=from-dotenv\n");

        EnvFile::safeLoad($this->directory);

        self::assertSame('from-dotenv', getenv('KINETIS_ENV_FILE_TEST_A'));
    }

    public function test_loading_a_missing_env_file_does_not_throw(): void
    {
        EnvFile::safeLoad($this->directory);

        self::assertFalse(getenv('KINETIS_ENV_FILE_TEST_A'));
    }

    public function test_a_real_environment_variable_already_set_is_not_overwritten(): void
    {
        putenv('KINETIS_ENV_FILE_TEST_B=real-deployment-value');
        file_put_contents($this->directory . '/.env', "KINETIS_ENV_FILE_TEST_B=from-dotenv\n");

        EnvFile::safeLoad($this->directory);

        self::assertSame('real-deployment-value', getenv('KINETIS_ENV_FILE_TEST_B'));
    }
}
