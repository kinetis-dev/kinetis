<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\MailerAssembler;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Mailer\SafeMailer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs the same sequence `MailerFactory::fromConfig()` runs, over the
 * production registry unless a test names a fixture, and over a mock
 * HTTP client unless a test hands in a recording one. The DSN is marked
 * sensitive on every helper, so a secrecy test's own frames do not hand
 * the trace what the package's frames withhold.
 */
trait AssemblesMailers
{
    private const string DSN_KEY = 'MAILER_DSN';

    private const string PROFILE_KEY = 'MAILER_ALLOW_INSECURE_LOCAL';

    private function build(
        #[\SensitiveParameter] string $dsn,
        ?TransportRegistry $registry = null,
        AppEnv $environment = AppEnv::Development,
        bool $allowInsecureLocal = false,
        ?HttpClientInterface $client = null,
    ): MailerInterface {
        $values = ['APP_ENV' => $environment->value, self::DSN_KEY => $dsn];

        if ($allowInsecureLocal) {
            $values[self::PROFILE_KEY] = 'true';
        }

        return new MailerAssembler($registry ?? TransportRegistry::supported(), $client ?? new MockHttpClient())
            ->assemble(new Config($values), self::DSN_KEY, self::PROFILE_KEY);
    }

    private function buildTransport(
        #[\SensitiveParameter] string $dsn,
        ?TransportRegistry $registry = null,
        AppEnv $environment = AppEnv::Development,
        bool $allowInsecureLocal = false,
        ?HttpClientInterface $client = null,
    ): TransportInterface {
        return $this->transportOf($this->build($dsn, $registry, $environment, $allowInsecureLocal, $client));
    }

    private function expectRejection(
        #[\SensitiveParameter] string $dsn,
        string $reason,
        ?TransportRegistry $registry = null,
        AppEnv $environment = AppEnv::Development,
        bool $allowInsecureLocal = false,
    ): MailerConfigurationException {
        try {
            $this->build($dsn, $registry, $environment, $allowInsecureLocal);
        } catch (MailerConfigurationException $e) {
            self::assertStringContainsString($reason, $e->getMessage());

            return $e;
        }

        self::fail('The DSN was accepted.');
    }

    private function transportOf(MailerInterface $mailer): TransportInterface
    {
        $inner = new \ReflectionProperty(SafeMailer::class, 'mailer')->getValue($mailer);
        self::assertInstanceOf(Mailer::class, $inner);

        /** @var TransportInterface $transport */
        $transport = new \ReflectionProperty($inner, 'transport')->getValue($inner);

        return $transport;
    }
}
