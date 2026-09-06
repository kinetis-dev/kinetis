<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Config\Config;
use Kinetis\Mailer\Dsn\DsnParser;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Policy\TransportPolicy;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Runtime\AppEnvironment;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The sequence {@see MailerFactory::fromConfig()} runs — read, parse,
 * judge, build, wrap — over a given registry and HTTP client.
 *
 * The public factory constructs this over
 * {@see TransportRegistry::supported()} and {@see MailerFactory::httpClient()}
 * and exposes neither choice. The class exists so the package's own tests
 * can run the identical sequence over a registry whose entries name test
 * factories, which is the only way to reach the refusals no official
 * factory ever triggers — a `supports()` that throws, a `create()` that
 * returns an impostor — and to hand every official factory a recording
 * client. Constructing one is not a consumer facility.
 *
 * One boundary wraps the whole assembly. Everything inside is
 * configuration handling and vendor construction, and a raw throwable
 * from any of it would carry the DSN it was reading into a message and a
 * trace. An exception this package already built is already safe and
 * passes through unchanged rather than being wrapped twice.
 *
 * @internal to kinetis/mailer
 */
final readonly class MailerAssembler
{
    public function __construct(
        private TransportRegistry $registry,
        #[\SensitiveParameter]
        private HttpClientInterface $client,
    ) {}

    /**
     * @param string $dsnKey     the config key holding the DSN
     * @param string $profileKey the config key selecting the local-insecure profile
     *
     * @throws MailerConfigurationException
     */
    public function assemble(#[\SensitiveParameter] Config $config, string $dsnKey, string $profileKey): MailerInterface
    {
        try {
            return $this->build($config, $dsnKey, $profileKey);
        } catch (MailerConfigurationException $safe) {
            throw $safe;
        } catch (Throwable) {
            throw MailerConfigurationException::rejected($dsnKey, MailerConfigurationException::ASSEMBLY_FAILED);
        }
    }

    private function build(#[\SensitiveParameter] Config $config, string $dsnKey, string $profileKey): MailerInterface
    {
        $dsn = $config->get($dsnKey);

        if ($dsn === null || $dsn === '') {
            throw MailerConfigurationException::notSet($dsnKey);
        }

        $policy = TransportPolicy::for(
            $dsnKey,
            $profileKey,
            // Read out of the same Config snapshot the DSN came from,
            // never out of the ambient process environment: a snapshot
            // that says nothing about APP_ENV means Production here, not
            // whatever getenv() happens to answer in this process.
            AppEnvironment::detect($config->string('APP_ENV', '')),
            $this->allowsInsecureLocal($config, $profileKey),
            $this->registry,
        );

        $node = DsnParser::parse($dsn, $dsnKey);
        $policy->validate($node);

        return new SafeMailer(new Mailer(new TransportBuilder($policy, $this->client, $dsnKey)->build($node)));
    }

    /**
     * @throws MailerConfigurationException
     */
    private function allowsInsecureLocal(#[\SensitiveParameter] Config $config, string $profileKey): bool
    {
        try {
            return $config->bool($profileKey, false);
        } catch (Throwable) {
            throw MailerConfigurationException::rejected(
                $profileKey,
                MailerConfigurationException::UNREADABLE,
            );
        }
    }
}
