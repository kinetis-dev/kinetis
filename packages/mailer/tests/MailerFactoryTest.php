<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\MailerFactory;
use Kinetis\Mailer\NoRedirectHttpClient;
use Kinetis\Mailer\SafeMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class MailerFactoryTest extends TestCase
{
    use AssemblesMailers;
    use RendersThrowables;

    private const string SECRET = 'sw0rdf1shSENTINEL';

    public function test_a_configured_dsn_builds_a_wrapped_mailer(): void
    {
        $mailer = MailerFactory::fromConfig(new Config(['MAILER_DSN' => 'null://null']));

        self::assertInstanceOf(SafeMailer::class, $mailer);
        self::assertInstanceOf(NullTransport::class, $this->transportOf($mailer));
    }

    public function test_a_named_connection_reads_its_own_scoped_dsn(): void
    {
        // Two different schemes rather than two identical `null://null`
        // DSNs: if the named lookup ever fell back to the default's own
        // key, both transports would still come back as NullTransport and
        // this would keep passing for the wrong reason.
        $config = new Config([
            'MAILER_DSN' => 'null://null',
            'MAILER_TRANSACTIONAL_DSN' => 'smtps://user:pass@mail.example.com',
        ]);

        self::assertInstanceOf(NullTransport::class, $this->transportOf(MailerFactory::fromConfig($config)));
        self::assertInstanceOf(
            EsmtpTransport::class,
            $this->transportOf(MailerFactory::fromConfig($config, 'transactional')),
        );
    }

    public function test_a_named_connection_reads_its_own_scoped_security_profile(): void
    {
        $config = new Config([
            'APP_ENV' => 'development',
            'MAILER_DSN' => 'smtps://user:pass@mail.example.com',
            'MAILER_MAILPIT_DSN' => 'smtp://127.0.0.1:1025',
            'MAILER_MAILPIT_ALLOW_INSECURE_LOCAL' => 'true',
        ]);

        self::assertInstanceOf(
            EsmtpTransport::class,
            $this->transportOf(MailerFactory::fromConfig($config, 'mailpit')),
        );

        // The default connection never inherits the named one's profile.
        $this->expectExceptionMessage(MailerConfigurationException::WRONG_CREDENTIALS);
        MailerFactory::fromConfig(new Config([
            'APP_ENV' => 'development',
            'MAILER_DSN' => 'smtp://127.0.0.1:1025',
            'MAILER_MAILPIT_ALLOW_INSECURE_LOCAL' => 'true',
        ]));
    }

    public function test_a_composite_builds_one_transport_from_already_built_children(): void
    {
        $mailer = MailerFactory::fromConfig(new Config([
            'MAILER_DSN' => 'failover(smtps://u:p@a.example.com smtps://u:p@b.example.com)?retry_period=30',
        ]));

        self::assertInstanceOf(FailoverTransport::class, $this->transportOf($mailer));
    }

    public function test_a_missing_dsn_names_its_own_key(): void
    {
        $this->expectException(MailerConfigurationException::class);
        $this->expectExceptionMessage('MAILER_DSN');

        MailerFactory::fromConfig(new Config([]));
    }

    public function test_a_named_connections_missing_dsn_names_its_own_scoped_key(): void
    {
        $this->expectException(MailerConfigurationException::class);
        $this->expectExceptionMessage('MAILER_TRANSACTIONAL_DSN');

        MailerFactory::fromConfig(new Config([]), 'transactional');
    }

    public function test_a_blank_dsn_is_treated_as_unset_rather_than_as_a_transport(): void
    {
        $this->expectExceptionMessage(MailerConfigurationException::NOT_SET);

        MailerFactory::fromConfig(new Config(['MAILER_DSN' => '']));
    }

    public function test_an_unreadable_security_profile_flag_is_a_configuration_error(): void
    {
        $this->expectExceptionMessage('MAILER_ALLOW_INSECURE_LOCAL');

        MailerFactory::fromConfig(new Config([
            'MAILER_DSN' => 'null://null',
            'MAILER_ALLOW_INSECURE_LOCAL' => 'perhaps',
        ]));
    }

    public function test_an_unknown_app_env_is_production_rather_than_development(): void
    {
        $this->expectExceptionMessage(MailerConfigurationException::LOCAL_ONLY);

        MailerFactory::fromConfig(new Config(['APP_ENV' => 'staging', 'MAILER_DSN' => 'sendmail://default']));
    }

    public function test_app_env_is_read_from_the_config_snapshot_not_the_process_environment(): void
    {
        putenv('APP_ENV=development');

        try {
            MailerFactory::fromConfig(new Config(['MAILER_DSN' => 'sendmail://default']));
            self::fail('An ambient APP_ENV was allowed to select the development-only transport.');
        } catch (MailerConfigurationException $e) {
            self::assertStringContainsString(MailerConfigurationException::LOCAL_ONLY, $e->getMessage());
        } finally {
            putenv('APP_ENV');
        }
    }

    public function test_an_invalid_connection_name_never_reaches_a_config_key(): void
    {
        $this->expectException(MailerConfigurationException::class);

        MailerFactory::fromConfig(new Config(['MAILER_DSN' => 'null://null']), '../etc');
    }

    public function test_the_factory_offers_no_way_to_substitute_the_registry(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionMethod(MailerFactory::class, 'fromConfig')->getParameters(),
        );

        self::assertSame(['config', 'connection'], $parameters);
    }

    // --- the HTTP client -----------------------------------------------------------

    public function test_api_transports_are_given_the_guarded_client(): void
    {
        self::assertInstanceOf(NoRedirectHttpClient::class, MailerFactory::httpClient());
    }

    public function test_a_bridge_transport_built_by_the_factory_holds_the_guarded_client(): void
    {
        $transport = $this->transportOf(MailerFactory::fromConfig(new Config(['MAILER_DSN' => 'sendgrid+api://KEY@default'])));

        self::assertInstanceOf(SendgridApiTransport::class, $transport);
        self::assertInstanceOf(
            NoRedirectHttpClient::class,
            new \ReflectionProperty(AbstractHttpTransport::class, 'client')->getValue($transport),
        );
    }

    // --- secrecy --------------------------------------------------------------------

    public function test_a_rejected_dsn_leaks_no_credential_through_any_rendering(): void
    {
        $this->assertNothingLeaks(new Config([
            'MAILER_DSN' => 'smtp://admin:' . self::SECRET . '@mail.example.com:25',
        ]));
    }

    public function test_a_dsn_carrying_a_token_in_its_query_leaks_nothing_and_keeps_no_cause(): void
    {
        $exception = $this->assertNothingLeaks(new Config([
            'MAILER_DSN' => 'smtps://admin:pass@mail.example.com',
            'MAILER_BROKEN_DSN' => 'null://null?token=' . self::SECRET,
        ]), 'broken');

        self::assertNull($exception->getPrevious(), 'no vendor exception is chained');
    }

    public function test_a_rejected_dsn_leaks_nothing_through_the_config_snapshot_in_the_trace(): void
    {
        $this->assertNothingLeaks(new Config([
            'APP_ENV' => 'production',
            'DB_PASSWORD' => self::SECRET,
            'MAILER_DSN' => 'smtp://mail.example.com:25',
        ]));
    }

    public function test_a_provider_credential_the_real_factory_refuses_leaks_nothing(): void
    {
        // A pair scheme handed one half: the real bridge factory is what
        // the assembly reaches, and the refusal is judged before it does.
        $this->assertNothingLeaks(new Config([
            'MAILER_DSN' => 'mailgun+api://' . self::SECRET . '@default',
        ]));
    }

    private function assertNothingLeaks(#[\SensitiveParameter] Config $config, string $connection = 'default'): MailerConfigurationException
    {
        try {
            MailerFactory::fromConfig($config, $connection);
        } catch (MailerConfigurationException $e) {
            self::assertNull($e->getPrevious(), 'no cause is retained');
            $this->assertNoSentinelSurvives($e, [self::SECRET]);

            return $e;
        }

        self::fail('The DSN was accepted.');
    }
}
