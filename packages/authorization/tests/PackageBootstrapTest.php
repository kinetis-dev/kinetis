<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\AuthorizationExceptionMiddleware;
use Kinetis\Authorization\Gate;
use Kinetis\Authorization\PackageBootstrap;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_registers_the_exception_translating_middleware_globally(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertContains(AuthorizationExceptionMiddleware::class, $app->middlewares());
    }

    public function test_gate_resolves_through_plain_autowiring_with_no_explicit_binding(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertFalse($app->has(Gate::class));
        self::assertInstanceOf(Gate::class, $app->get(Gate::class));
    }
}
