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
}
