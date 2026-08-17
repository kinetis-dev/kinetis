<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Mailer\PackageBootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_dsn_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(MailerInterface::class));
    }

    public function test_a_configured_dsn_binds_a_mailer(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'null://null']));
        $app->boot();

        self::assertInstanceOf(MailerInterface::class, $app->get(MailerInterface::class));
    }

    public function test_an_invalid_dsn_fails_at_first_use_not_at_boot(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'not-a-dsn']));
        $app->boot();

        $this->expectException(\Throwable::class);

        $app->get(MailerInterface::class);
    }
}
