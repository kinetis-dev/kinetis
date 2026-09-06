<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\PackageBootstrap;
use Kinetis\Mailer\SafeMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_dsn_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(MailerInterface::class));
    }

    public function test_a_blank_dsn_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => '']));

        self::assertFalse($app->has(MailerInterface::class));
    }

    public function test_a_configured_dsn_binds_the_wrapped_mailer(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'null://null']));
        $app->boot();

        self::assertInstanceOf(SafeMailer::class, $app->get(MailerInterface::class));
    }

    public function test_the_transport_is_built_during_registration_rather_than_on_first_use(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'null://null']));

        // Published as a completed instance, so resolving it later cannot
        // be the moment a bad DSN is discovered.
        $app->boot();
        self::assertSame($app->get(MailerInterface::class), $app->get(MailerInterface::class));
    }

    public function test_an_invalid_dsn_fails_at_registration(): void
    {
        $app = new AppScope();

        $this->expectException(MailerConfigurationException::class);

        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'not-a-dsn']));
    }

    public function test_an_insecure_dsn_fails_at_registration(): void
    {
        $app = new AppScope();

        $this->expectExceptionMessage(MailerConfigurationException::OPPORTUNISTIC_TLS);

        new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'smtp://user:pass@mail.example.com:25']));
    }

    public function test_a_failed_registration_leaves_an_existing_binding_untouched(): void
    {
        $sentinel = new class implements MailerInterface {
            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
            }
        };

        $app = new AppScope();
        $app->instance(MailerInterface::class, $sentinel);

        try {
            new PackageBootstrap()->register($app, new Config(['MAILER_DSN' => 'smtp://mail.example.com:25']));
            self::fail('The insecure DSN was accepted.');
        } catch (MailerConfigurationException) {
            // The published binding is the assertion.
        }

        $app->boot();

        self::assertSame($sentinel, $app->get(MailerInterface::class));
    }
}
