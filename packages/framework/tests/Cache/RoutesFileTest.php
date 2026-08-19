<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\RoutesFile;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\BootstrapMarker;
use PHPUnit\Framework\TestCase;

final class RoutesFileTest extends TestCase
{
    public function test_loads_the_bootstrap_callable_from_an_existing_file(): void
    {
        $app = new AppScope();

        RoutesFile::loadBootstrap(__DIR__ . '/Fixtures')($app, new Config([]));

        self::assertTrue($app->has(BootstrapMarker::class));
    }

    public function test_returns_a_no_op_callable_when_bootstrap_file_is_absent(): void
    {
        $app = new AppScope();

        RoutesFile::loadBootstrap(__DIR__ . '/Fixtures/does-not-exist')($app, new Config([]));

        self::assertFalse($app->has(BootstrapMarker::class));
    }

    /**
     * A stale production cache can name a bootstrap whose package was
     * removed without rebuilding — commands.php survives a composer
     * remove. Skipped with a warning, like the live discovery path
     * skips a declared-but-missing class, rather than a fatal that
     * takes the application down until someone rebuilds.
     */
    public function test_a_cached_bootstrap_class_that_no_longer_exists_is_skipped(): void
    {
        $chain = RoutesFile::loadBootstrap(
            __DIR__ . '/Fixtures',
            ['Kinetis\\Removed\\PackageBootstrap'],
        );

        $app = new AppScope();
        $previous = ini_set('error_log', '/dev/null');

        try {
            $chain($app, new Config([]));
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        // The application's own bootstrap.php still ran.
        self::assertTrue($app->has(BootstrapMarker::class));
    }
}
