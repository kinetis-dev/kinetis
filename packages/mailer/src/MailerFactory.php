<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

/**
 * Builds a Symfony\Component\Mailer\MailerInterface from a MAILER_DSN,
 * with Kinetis\RevoltHttpClient\AmpHttpClientFactory::create() injected as
 * Transport::fromDsn()'s HttpClientInterface — the same pattern
 * Kinetis\StorageS3\S3FilesystemFactory and Kinetis\QueueSqs\SqsClientFactory
 * use. Only an API-based transport (Sendgrid, Mailgun, Postmark, SES, ...)
 * uses that client; EsmtpTransport has no HttpClientInterface parameter and
 * opens a blocking socket. Passing the client unconditionally still works:
 * Transport::fromDsn() hands it to every registered factory and the ones
 * with no use for it ignore it, so no branching on the DSN's scheme is
 * needed here.
 *
 * Whichever transport-specific package a DSN scheme needs
 * (symfony/sendgrid-mailer, symfony/mailgun-mailer, ...) is the
 * consumer's own composer.json to add — Transport::fromDsn() discovers
 * them via its own class_exists()-gated factory list, so this class needs
 * no dispatch logic of its own.
 *
 * $connection selects a named connection via Config::scopedKey(), the
 * convention every other *Factory::fromConfig() in this project follows.
 */
final class MailerFactory
{
    /**
     * MAILER_TIMEOUT, in seconds, is both the idle timeout between bytes
     * and the total duration of one API send, so a response fed a byte
     * at a time cannot outlive it; no redirect is followed, so the
     * provider endpoint the DSN names is the only one contacted. SMTP
     * ignores all three and carries its own timeouts from the DSN.
     */
    public static function fromConfig(Config $config, string $connection = 'default'): MailerInterface
    {
        $dsn = $config->required(Config::scopedKey('MAILER_DSN', $connection));

        $timeoutKey = Config::scopedKey('MAILER_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, 30.0);

        if ($timeout <= 0.0) {
            throw new InvalidArgumentException("{$timeoutKey} must be a positive number of seconds.");
        }

        $client = AmpHttpClientFactory::create([
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'max_redirects' => 0,
        ]);

        return new Mailer(Transport::fromDsn($dsn, client: $client));
    }
}
