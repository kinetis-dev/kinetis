<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Mailer\MailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\NullTransport;

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
        // Deliberately different schemes, not two identical `null://null`
        // DSNs — if the named-connection lookup ever fell back to reading
        // the default's own key instead of its scoped one, both transports
        // would still come back as NullTransport and this test would keep
        // passing for the wrong reason. EsmtpTransport's constructor never
        // connects eagerly (confirmed directly against the real library,
        // not assumed), so this needs no network access either.
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

    private function transportOf(MailerInterface $mailer): object
    {
        $property = new \ReflectionProperty($mailer, 'transport');

        /** @var object $transport */
        $transport = $property->getValue($mailer);

        return $transport;
    }
}
