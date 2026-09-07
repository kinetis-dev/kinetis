<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Mailer\MailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class MailerFactoryTest extends TestCase
{
    public function test_builds_a_mailer_for_the_default_connection(): void
    {
        $config = new Config(['MAILER_DSN' => 'null://null']);

        $mailer = MailerFactory::fromConfig($config);

        self::assertInstanceOf(MailerInterface::class, $mailer);
        self::assertInstanceOf(NullTransport::class, $this->transportOf($mailer));
    }

    public function test_a_named_connection_reads_its_own_dsn_not_the_defaults(): void
    {
        // Two different schemes, not two identical `null://null` DSNs: if
        // the named-connection lookup read the default's own key, both
        // transports would still come back as NullTransport and this test
        // would pass for the wrong reason. EsmtpTransport's constructor
        // does not connect, so this needs no network access.
        $config = new Config([
            'MAILER_DSN' => 'null://null',
            'MAILER_TRANSACTIONAL_DSN' => 'smtp://user:pass@localhost:2525',
        ]);

        $default = MailerFactory::fromConfig($config);
        $transactional = MailerFactory::fromConfig($config, 'transactional');

        self::assertInstanceOf(NullTransport::class, $this->transportOf($default));
        self::assertInstanceOf(EsmtpTransport::class, $this->transportOf($transactional));
    }

    public function test_a_missing_dsn_throws_a_clear_error(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('MAILER_DSN');
        MailerFactory::fromConfig($config);
    }

    public function test_a_named_connections_missing_dsn_names_its_own_scoped_key(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('MAILER_TRANSACTIONAL_DSN');
        MailerFactory::fromConfig($config, 'transactional');
    }

    /**
     * symfony/sendgrid-mailer is a dev dependency for exactly this: an
     * API bridge Transport::fromDsn() resolves to a transport that keeps
     * the injected HttpClientInterface, so the options can be read back
     * off the client the send runs through.
     */
    public function test_the_default_timeout_and_no_redirects_reach_an_api_bridges_client(): void
    {
        $config = new Config(['MAILER_DSN' => 'sendgrid+api://KEY@default']);

        $options = $this->clientOptionsOf(MailerFactory::fromConfig($config));

        self::assertSame(30.0, $options['timeout']);
        self::assertSame(30.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
    }

    public function test_a_configured_timeout_bounds_both_idle_and_total_duration(): void
    {
        $config = new Config([
            'MAILER_DSN' => 'sendgrid+api://KEY@default',
            'MAILER_TIMEOUT' => '2.5',
        ]);

        $options = $this->clientOptionsOf(MailerFactory::fromConfig($config));

        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
    }

    public function test_a_named_connection_reads_its_own_timeout(): void
    {
        $config = new Config([
            'MAILER_DSN' => 'sendgrid+api://KEY@default',
            'MAILER_TIMEOUT' => '2.5',
            'MAILER_TRANSACTIONAL_DSN' => 'sendgrid+api://KEY@default',
            'MAILER_TRANSACTIONAL_TIMEOUT' => '7.5',
        ]);

        $options = $this->clientOptionsOf(MailerFactory::fromConfig($config, 'transactional'));

        self::assertSame(7.5, $options['timeout']);
        self::assertSame(7.5, $options['max_duration']);
    }

    public function test_a_zero_timeout_is_rejected(): void
    {
        $config = new Config(['MAILER_DSN' => 'null://null', 'MAILER_TIMEOUT' => '0']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAILER_TIMEOUT must be a positive number of seconds.');
        MailerFactory::fromConfig($config);
    }

    public function test_a_negative_timeout_is_rejected(): void
    {
        $config = new Config(['MAILER_DSN' => 'null://null', 'MAILER_TIMEOUT' => '-1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAILER_TIMEOUT must be a positive number of seconds.');
        MailerFactory::fromConfig($config);
    }

    public function test_a_named_connections_rejected_timeout_names_its_own_scoped_key(): void
    {
        $config = new Config([
            'MAILER_TRANSACTIONAL_DSN' => 'null://null',
            'MAILER_TRANSACTIONAL_TIMEOUT' => '0',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAILER_TRANSACTIONAL_TIMEOUT must be a positive number of seconds.');
        MailerFactory::fromConfig($config, 'transactional');
    }

    private function transportOf(MailerInterface $mailer): object
    {
        $property = new \ReflectionProperty($mailer, 'transport');

        /** @var object $transport */
        $transport = $property->getValue($mailer);

        return $transport;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptionsOf(MailerInterface $mailer): array
    {
        $transport = $this->transportOf($mailer);
        self::assertInstanceOf(SendgridApiTransport::class, $transport);

        $client = new \ReflectionProperty($transport, 'client')->getValue($transport);
        self::assertInstanceOf(AmpHttpClient::class, $client);

        /** @var array<string, mixed> $options */
        $options = new \ReflectionProperty($client, 'defaultOptions')->getValue($client);

        return $options;
    }
}
