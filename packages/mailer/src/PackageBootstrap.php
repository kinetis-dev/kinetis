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
 * The mailer is constructed here, not deferred to first use, so a
 * malformed DSN, an unusable MAILER_TIMEOUT, or a missing bridge package
 * fails at registration instead of inside whichever request or queued
 * job happens to send the first message. The application's own
 * `bootstrap.php` runs after this and still wins on the binding.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('MAILER_DSN', '') === '') {
            return;
        }

        $app->instance(MailerInterface::class, MailerFactory::fromConfig($config));
    }
}
