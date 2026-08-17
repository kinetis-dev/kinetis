<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Declared via `extra.kinetis`: with `MAILER_DSN` set, binds
 * {@see MailerInterface} so a controller, command, or queued job can
 * constructor-inject it with nothing else to register. Unset means
 * inert.
 *
 * The binding is a factory, resolved on first use rather than here, so
 * an application that never sends mail never builds a transport.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('MAILER_DSN', '') === '') {
            return;
        }

        $app->bind(
            MailerInterface::class,
            static fn (): MailerInterface => MailerFactory::fromConfig($config),
        );
    }
}
