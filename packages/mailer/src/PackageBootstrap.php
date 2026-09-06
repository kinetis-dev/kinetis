<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * Declared via `extra.kinetis`: with `MAILER_DSN` set, binds
 * {@see MailerInterface} so a controller, command, or queued job can
 * constructor-inject it with nothing else to register. Unset or blank
 * means inert — the package binds nothing and an application that never
 * sends mail is unaffected by having it installed.
 *
 * A configured DSN is parsed, judged and built here, during registration,
 * rather than on first use. Every transport factory in the supported
 * registry constructs without touching the network, so proving the
 * configuration costs a boot's worth of work and nothing more — and a
 * plaintext SMTP DSN that only fails the first time an application tries
 * to send mail is a production incident rather than a failed deploy.
 *
 * The completed mailer is assembled into a local value first and
 * published with a single `AppScope::instance()` call afterwards. A DSN
 * that fails publishes nothing at all, so whatever binding was already
 * registered under {@see MailerInterface} — a test double, an
 * application's own — is left exactly as it was.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    /**
     * @throws MailerConfigurationException when `MAILER_DSN` is set and
     *     names a transport this package refuses to build
     */
    #[\Override]
    public function register(AppScope $app, #[\SensitiveParameter] Config $config): void
    {
        if (!self::isConfigured($config)) {
            return;
        }

        $mailer = MailerFactory::fromConfig($config);

        $app->instance(MailerInterface::class, $mailer);
    }

    /**
     * The probe reads the same snapshot the factory will, through the
     * same safe boundary: a Config that cannot answer is a configuration
     * error naming its key, not a raw typed exception escaping the one
     * path every other failure here takes.
     */
    private static function isConfigured(#[\SensitiveParameter] Config $config): bool
    {
        try {
            return $config->string('MAILER_DSN', '') !== '';
        } catch (Throwable) {
            throw MailerConfigurationException::rejected('MAILER_DSN', MailerConfigurationException::UNREADABLE);
        }
    }
}
