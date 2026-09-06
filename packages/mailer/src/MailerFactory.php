<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Config\Config;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a `MailerInterface` from `MAILER_DSN`, in one order that does
 * not vary: parse the complete DSN into a Kinetis-owned tree, judge the
 * whole tree against the supported transport registry, then build it.
 *
 * Nothing is constructed while anything is still unjudged. A leaf reaches
 * its factory only after {@see \Kinetis\Mailer\Policy\TransportPolicy}
 * has approved every leaf in the DSN, so a `failover(...)` whose second
 * member is plaintext SMTP builds neither member. The complete DSN string
 * never reaches Symfony at all — see {@see \Kinetis\Mailer\Dsn\DsnParser}
 * for why a second parser is the thing being avoided — and no scheme
 * outside {@see TransportRegistry} is built at all.
 *
 * The registry is {@see TransportRegistry::supported()} and nothing else.
 * There is no parameter, setting or subclass through which a consumer
 * substitutes another: a registry that admitted one more scheme, one
 * more factory or one more option would be a policy this package no
 * longer owns. The sequence itself lives in {@see MailerAssembler},
 * which this factory runs over the supported registry and
 * {@see httpClient()}.
 *
 * The HTTP client injected into API transports is
 * `AmpHttpClientFactory::createWithoutRetries()` behind
 * {@see NoRedirectHttpClient}. A transparent retry of a mail POST whose
 * response never arrived can deliver the message twice, and this package
 * publishes no idempotency or replay contract that would make a second
 * attempt safe; the decorator holds the rest of the wire boundary. SMTP
 * ignores the client — `EsmtpTransport` has no `HttpClientInterface`
 * parameter and opens a raw socket regardless — which is why passing it
 * unconditionally needs no branch on the scheme.
 *
 * Whichever bridge package a scheme needs (`symfony/sendgrid-mailer`,
 * `symfony/amazon-mailer`, ...) is the consumer's own composer.json to
 * add; the registry names each factory as a string and loads it only when
 * its scheme is selected.
 *
 * `$connection` selects a named connection through `Config::scopedKey()`,
 * the convention every other `*Factory::fromConfig()` here follows, after
 * {@see ConnectionName} has confined the name.
 */
final class MailerFactory
{
    /**
     * @throws MailerConfigurationException every failure, including a
     *     missing key, an unreadable `MAILER_ALLOW_INSECURE_LOCAL`, and
     *     anything Config, the registry or Symfony raises underneath
     */
    public static function fromConfig(#[\SensitiveParameter] Config $config, string $connection = 'default'): MailerInterface
    {
        $connection = ConnectionName::validated($connection);

        return new MailerAssembler(TransportRegistry::supported(), self::httpClient())->assemble(
            $config,
            Config::scopedKey('MAILER_DSN', $connection),
            Config::scopedKey('MAILER_ALLOW_INSECURE_LOCAL', $connection),
        );
    }

    /**
     * The client every API transport built here is handed, and the only
     * one this package constructs. A consumer assembling a Symfony
     * transport by hand gets the same request policy by passing this:
     * the target is absolute HTTPS carrying no userinfo, the certificate
     * is verified, no redirect is followed, and a request is put on the
     * wire once. {@see NoRedirectHttpClient} states what that policy does
     * and does not cover.
     */
    public static function httpClient(): HttpClientInterface
    {
        return new NoRedirectHttpClient(AmpHttpClientFactory::createWithoutRetries());
    }
}
