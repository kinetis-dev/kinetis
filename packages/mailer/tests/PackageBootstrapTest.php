<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Mailer\PackageBootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;

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

    public function test_a_malformed_dsn_fails_at_registration(): void
    {
        $app = new AppScope();

        $this->expectException(\Throwable::class);

        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'not-a-dsn']));
    }

    public function test_an_unusable_timeout_fails_at_registration(): void
    {
        $app = new AppScope();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAILER_TIMEOUT must be a positive number of seconds.');

        new PackageBootstrap()->register(
            $app,
            new Config(['MAILER_DSN' => 'null://null', 'MAILER_TIMEOUT' => '0']),
        );
    }

    public function test_an_application_registration_replaces_this_one(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'null://null']));

        $own = new Mailer(new NullTransport());
        $app->instance(MailerInterface::class, $own);
        $app->boot();

        self::assertSame($own, $app->get(MailerInterface::class));
    }
}
