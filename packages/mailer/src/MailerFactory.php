<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

/**
 * Builds a Symfony\Component\Mailer\MailerInterface from a MAILER_DSN,
 * with Kinetis\RevoltHttpClient\AmpHttpClientFactory::create() injected as
 * Transport::fromDsn()'s HttpClientInterface — the identical pattern
 * Kinetis\StorageS3\S3FilesystemFactory/Kinetis\QueueSqs\SqsClientFactory
 * already establish. This only benefits an API-based transport (Sendgrid,
 * Mailgun, Postmark, SES, ...); EsmtpTransport has no HttpClientInterface
 * parameter at all — confirmed by reading its constructor directly, not
 * assumed — and opens a raw, genuinely blocking socket regardless. Passing
 * the client unconditionally is still correct: Transport::fromDsn() hands
 * it to every registered factory, and the ones that don't use it simply
 * ignore it, so this needs no branching on the DSN's own scheme.
 *
 * Whichever transport-specific package a DSN scheme needs
 * (symfony/sendgrid-mailer, symfony/mailgun-mailer, ...) is the
 * consumer's own composer.json to add — Transport::fromDsn() discovers
 * them via its own class_exists()-gated factory list, so this class needs
 * no dispatch logic of its own.
 *
 * $connection selects a named connection via Config::scopedKey(), the same
 * convention every other *Factory::fromConfig() in this project follows.
 */
final class MailerFactory
{
    public static function fromConfig(Config $config, string $connection = 'default'): MailerInterface
    {
        $dsn = $config->required(Config::scopedKey('MAILER_DSN', $connection));

        return new Mailer(Transport::fromDsn($dsn, client: AmpHttpClientFactory::create()));
    }
}
