<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Config\Config;
use Kinetis\Mailer\Dsn\DsnParser;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\MailerAssembler;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Mailer\SafeMailer;
use Kinetis\Mailer\Tests\Doubles\RegistryFixture;
use Kinetis\Mailer\Tests\Doubles\StubTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\Google\Transport\GmailSmtpTransport;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridSmtpTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class TransportPolicyTest extends TestCase
{
    use AssemblesMailers;

    #[\Override]
    protected function setUp(): void
    {
        StubTransportFactory::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        StubTransportFactory::reset();
    }

    // --- SMTP ----------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedProductionSmtp(): iterable
    {
        yield 'implicit TLS' => ['smtps://user:pass@mail.example.com'];
        yield 'implicit TLS on a port' => ['smtps://user:pass@mail.example.com:465'];
        yield 'required STARTTLS' => ['smtp://user:pass@mail.example.com:587?require_tls=true'];
        yield 'required STARTTLS with 1' => ['smtp://user:pass@mail.example.com:587?require_tls=1'];
        yield 'peer verification stated explicitly' => ['smtps://user:pass@mail.example.com?verify_peer=true'];
        yield 'an IPv4 relay' => ['smtps://user:pass@198.51.100.7'];
        yield 'an IPv6 relay' => ['smtps://user:pass@[2001:db8::1]:465'];
        yield 'a full option set' => [
            'smtps://user:pass@mail.example.com?local_domain=relay.example.com&max_per_second=2.5'
            . '&restart_threshold=100&restart_threshold_sleep=1&ping_threshold=30&source_ip=10.0.0.4'
            . '&peer_fingerprint=' . str_repeat('ab', 32),
        ];
    }

    #[DataProvider('acceptedProductionSmtp')]
    public function test_secure_smtp_is_accepted_in_production(string $dsn): void
    {
        self::assertInstanceOf(SafeMailer::class, $this->build($dsn, environment: AppEnv::Production));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedProductionSmtp(): iterable
    {
        yield 'plaintext' => ['smtp://user:pass@mail.example.com:25', MailerConfigurationException::OPPORTUNISTIC_TLS];
        yield 'opportunistic TLS only' => ['smtp://user:pass@mail.example.com:587?auto_tls=true', MailerConfigurationException::OPPORTUNISTIC_TLS];
        yield 'STARTTLS turned off' => ['smtp://user:pass@mail.example.com?auto_tls=false&require_tls=true', MailerConfigurationException::OPPORTUNISTIC_TLS];
        yield 'required STARTTLS declined' => ['smtp://user:pass@mail.example.com?require_tls=false', MailerConfigurationException::OPPORTUNISTIC_TLS];
        yield 'no credentials' => ['smtps://mail.example.com', MailerConfigurationException::WRONG_CREDENTIALS];
        yield 'a username with no password' => ['smtps://user@mail.example.com', MailerConfigurationException::WRONG_CREDENTIALS];
        yield 'peer verification off' => ['smtps://user:pass@mail.example.com?verify_peer=false', MailerConfigurationException::PEER_VERIFICATION_BYPASS];
        yield 'peer verification off as 0' => ['smtps://user:pass@mail.example.com?verify_peer=0', MailerConfigurationException::PEER_VERIFICATION_BYPASS];
        yield 'loopback without the profile' => ['smtp://127.0.0.1:1025', MailerConfigurationException::WRONG_CREDENTIALS];
    }

    #[DataProvider('refusedProductionSmtp')]
    public function test_insecure_smtp_is_refused(string $dsn, string $reason): void
    {
        $this->expectRejection($dsn, $reason, environment: AppEnv::Production);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedSmtpOptions(): iterable
    {
        yield 'a boolean typo on require_tls' => ['smtps://u:p@mail.example.com?require_tls=treu', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a boolean typo on verify_peer' => ['smtps://u:p@mail.example.com?verify_peer=treu', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a boolean spelled "on"' => ['smtps://u:p@mail.example.com?require_tls=on', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a boolean spelled "TRUE"' => ['smtps://u:p@mail.example.com?require_tls=TRUE', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'an unknown option' => ['smtps://u:p@mail.example.com?timeout=5', MailerConfigurationException::UNKNOWN_OPTION];
        yield 'a case-shifted known option' => ['smtps://u:p@mail.example.com?Require_Tls=true', MailerConfigurationException::UNKNOWN_OPTION];
        yield 'the composite option on a leaf' => ['smtps://u:p@mail.example.com?retry_period=15', MailerConfigurationException::UNKNOWN_OPTION];
        yield 'a sleep with no threshold' => ['smtps://u:p@mail.example.com?restart_threshold_sleep=2', MailerConfigurationException::MISSING_PREREQUISITE];
        yield 'a non-numeric threshold' => ['smtps://u:p@mail.example.com?restart_threshold=many', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a threshold of zero' => ['smtps://u:p@mail.example.com?restart_threshold=0', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a saturating threshold' => ['smtps://u:p@mail.example.com?restart_threshold=99999999999999999999', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'an overflowing rate' => ['smtps://u:p@mail.example.com?max_per_second=1e400', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a negative rate' => ['smtps://u:p@mail.example.com?max_per_second=-1', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a ping threshold beyond a day' => ['smtps://u:p@mail.example.com?ping_threshold=86401', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a source_ip that is not an address' => ['smtps://u:p@mail.example.com?source_ip=elsewhere', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'an unbracketed IPv6 source_ip' => ['smtps://u:p@mail.example.com?source_ip=2001:db8::1', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a fingerprint of the wrong width' => ['smtps://u:p@mail.example.com?peer_fingerprint=abcd', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a local_domain that is not a domain' => ['smtps://u:p@mail.example.com?local_domain=-nope-', MailerConfigurationException::BAD_OPTION_VALUE];
        yield 'a bracketed local_domain that is not an address' => ['smtps://u:p@mail.example.com?local_domain=%5Bnope%5D', MailerConfigurationException::BAD_OPTION_VALUE];
    }

    #[DataProvider('refusedSmtpOptions')]
    public function test_smtp_options_are_an_exact_typed_allowlist(string $dsn, string $reason): void
    {
        $this->expectRejection($dsn, $reason);
    }

    public function test_a_bracketed_ipv6_source_ip_is_accepted(): void
    {
        self::assertInstanceOf(
            SafeMailer::class,
            $this->build('smtps://u:p@mail.example.com?source_ip=%5B2001:db8::1%5D'),
        );
    }

    public function test_a_provider_smtp_scheme_cannot_turn_required_tls_off(): void
    {
        $this->expectRejection(
            'ses+smtp://USER:PASS@default?region=eu-west-1&require_tls=false',
            MailerConfigurationException::OPPORTUNISTIC_TLS,
        );
    }

    // --- the halves the built-in factory would drop ----------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function droppedHalves(): iterable
    {
        yield 'a username of 0' => ['smtps://0:secret@mail.example.com'];
        yield 'a password of 0' => ['smtps://user:0@mail.example.com'];
        yield 'a username escaped as %30' => ['smtps://%30:secret@mail.example.com'];
        yield 'a password escaped as %30' => ['smtps://user:%30@mail.example.com'];
        yield 'a username of 0 over required STARTTLS' => ['smtp://0:secret@mail.example.com:587?require_tls=true'];
        yield 'a password of 0 over required STARTTLS' => ['smtp://user:%30@mail.example.com:587?require_tls=true'];
    }

    #[DataProvider('droppedHalves')]
    public function test_a_half_the_built_in_factory_would_drop_is_refused(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::CREDENTIAL_DROPPED, environment: AppEnv::Production);
    }

    public function test_the_local_profile_does_not_admit_a_dropped_half(): void
    {
        $this->expectRejection(
            'smtps://user:0@127.0.0.1:1465',
            MailerConfigurationException::CREDENTIAL_DROPPED,
            allowInsecureLocal: true,
        );
    }

    public function test_a_bridge_configures_a_half_of_0_rather_than_dropping_it(): void
    {
        $gmail = $this->buildTransport('gmail+smtp://0:0@default');
        self::assertInstanceOf(GmailSmtpTransport::class, $gmail);
        self::assertSame('0', $gmail->getUsername());
        self::assertSame('0', $gmail->getPassword());

        $sendgrid = $this->buildTransport('sendgrid+smtp://0@default');
        self::assertInstanceOf(SendgridSmtpTransport::class, $sendgrid);
        self::assertSame('0', $sendgrid->getPassword());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function configuredHalves(): iterable
    {
        yield 'implicit TLS' => ['smtps://alice:s3cr%40t@mail.example.com', 'alice', 's3cr@t'];
        yield 'required STARTTLS' => ['smtp://alice:pass@mail.example.com:587?require_tls=true', 'alice', 'pass'];
        yield 'a half that is merely falsy-looking' => ['smtps://00:0.0@mail.example.com', '00', '0.0'];
    }

    #[DataProvider('configuredHalves')]
    public function test_an_accepted_core_smtp_leaf_configures_both_halves(string $dsn, string $user, string $password): void
    {
        $transport = $this->buildTransport($dsn, environment: AppEnv::Production);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame($user, $transport->getUsername());
        self::assertSame($password, $transport->getPassword());
    }

    // --- null and sendmail ----------------------------------------------------------

    public function test_the_discard_transport_is_accepted_in_production(): void
    {
        self::assertInstanceOf(SafeMailer::class, $this->build('null://null', environment: AppEnv::Production));
    }

    public function test_the_discard_transport_takes_no_option(): void
    {
        $this->expectRejection('null://null?quiet=true', MailerConfigurationException::UNKNOWN_OPTION);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function localProcessSchemes(): iterable
    {
        yield 'sendmail' => ['sendmail://default'];
        yield 'sendmail+smtp' => ['sendmail+smtp://default'];
    }

    #[DataProvider('localProcessSchemes')]
    public function test_a_local_process_transport_is_refused_outside_development(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::LOCAL_ONLY, environment: AppEnv::Production);
    }

    #[DataProvider('localProcessSchemes')]
    public function test_a_local_process_transport_is_accepted_in_development(string $dsn): void
    {
        self::assertInstanceOf(SafeMailer::class, $this->build($dsn));
    }

    public function test_a_sendmail_command_is_refused_rather_than_run(): void
    {
        $this->expectRejection('sendmail://default?command=%2Fbin%2Fsh', MailerConfigurationException::SENDMAIL_COMMAND);
    }

    public function test_native_is_refused_in_every_environment(): void
    {
        // php.ini decides at construction whether native:// is a local
        // process or a raw SMTP socket, so nothing about it can be judged
        // beforehand and it is not in the registry at all.
        foreach ([AppEnv::Development, AppEnv::Production] as $environment) {
            $this->expectRejection('native://default', MailerConfigurationException::UNSUPPORTED_SCHEME, environment: $environment);
        }
    }

    // --- the local-insecure profile --------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function loopbackSpellings(): iterable
    {
        yield 'localhost' => ['smtp://localhost:1025'];
        yield 'a dotted quad' => ['smtp://127.0.0.1:1025'];
        yield 'another address in 127/8' => ['smtp://127.0.0.2:1025'];
        yield 'IPv6' => ['smtp://[::1]:1025'];
        yield 'implicit TLS on loopback' => ['smtps://127.0.0.1:1465'];
    }

    #[DataProvider('loopbackSpellings')]
    public function test_the_profile_admits_one_direct_loopback_smtp_leaf(string $dsn): void
    {
        self::assertInstanceOf(SafeMailer::class, $this->build($dsn, allowInsecureLocal: true));
    }

    public function test_the_profile_relaxes_authentication_and_not_the_tls_a_scheme_carries(): void
    {
        $plain = $this->buildTransport('smtp://127.0.0.1:1025', allowInsecureLocal: true);
        self::assertInstanceOf(EsmtpTransport::class, $plain);
        self::assertSame('', $plain->getUsername());
        self::assertFalse($this->streamOf($plain)->isTLS());

        $implicit = $this->buildTransport('smtps://127.0.0.1:1465', allowInsecureLocal: true);
        self::assertInstanceOf(EsmtpTransport::class, $implicit);
        self::assertSame('', $implicit->getUsername());
        self::assertTrue($this->streamOf($implicit)->isTLS(), 'smtps:// is implicit TLS under the profile too');
    }

    /**
     * Every DSN the contract says the profile does not cover. Selecting
     * it against one of these is an error naming the profile key, not a
     * flag that quietly does nothing.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function outsideTheProfile(): iterable
    {
        yield 'a routable SMTP host' => ['smtp://user:pass@mail.example.com:587?require_tls=true'];
        yield 'the discard transport' => ['null://null'];
        yield 'sendmail' => ['sendmail://default'];
        yield 'sendmail+smtp' => ['sendmail+smtp://default'];
        yield 'an API transport' => ['sendgrid+api://KEY@default'];
        yield 'a provider SMTP transport' => ['gmail+smtp://user:pass@default'];
        yield 'a composite of one loopback leaf' => ['failover(smtp://127.0.0.1:1025)'];
        yield 'a composite of two loopback leaves' => ['failover(smtp://127.0.0.1:1025 smtp://127.0.0.2:1025)'];
        yield 'a mixed composite' => ['failover(smtp://127.0.0.1:1025 null://null)'];
        yield 'a nested all-loopback composite' => ['roundrobin(failover(smtp://127.0.0.1:1025))'];
    }

    #[DataProvider('outsideTheProfile')]
    public function test_the_profile_is_an_error_wherever_it_does_not_apply(string $dsn): void
    {
        try {
            $this->build($dsn, allowInsecureLocal: true);
        } catch (MailerConfigurationException $e) {
            self::assertStringContainsString(self::PROFILE_KEY, $e->getMessage());
            self::assertStringContainsString(MailerConfigurationException::LOOPBACK_ONLY, $e->getMessage());

            return;
        }

        self::fail('The profile was accepted where it does not apply.');
    }

    public function test_the_profile_is_refused_before_the_dsn_outside_development(): void
    {
        foreach (['production', 'staging', ''] as $appEnv) {
            try {
                new MailerAssembler(TransportRegistry::supported(), new MockHttpClient())->assemble(
                    new Config([
                        'APP_ENV' => $appEnv,
                        self::DSN_KEY => 'smtp://127.0.0.1:1025',
                        self::PROFILE_KEY => 'true',
                    ]),
                    self::DSN_KEY,
                    self::PROFILE_KEY,
                );
                self::fail("The profile was accepted with APP_ENV={$appEnv}.");
            } catch (MailerConfigurationException $e) {
                self::assertStringContainsString(self::PROFILE_KEY, $e->getMessage());
                self::assertStringContainsString(
                    MailerConfigurationException::INSECURE_LOCAL_IN_PRODUCTION,
                    $e->getMessage(),
                );
            }
        }
    }

    public function test_the_profile_never_covers_a_certificate_bypass(): void
    {
        $this->expectRejection(
            'smtp://127.0.0.1:1025?verify_peer=false',
            MailerConfigurationException::PEER_VERIFICATION_BYPASS,
            allowInsecureLocal: true,
        );
    }

    // --- composites ----------------------------------------------------------------------

    public function test_a_composite_of_secure_members_builds_both_transports(): void
    {
        $transport = $this->buildTransport(
            'failover(smtps://u:p@a.example.com smtps://u:p@b.example.com)?retry_period=15',
        );

        self::assertInstanceOf(FailoverTransport::class, $transport);
        self::assertSame(15, $this->retryPeriodOf($transport));
    }

    public function test_a_round_robin_composite_builds_its_own_transport_class(): void
    {
        $transport = $this->buildTransport('roundrobin(null://null null://null)');

        self::assertInstanceOf(RoundRobinTransport::class, $transport);
        self::assertNotInstanceOf(FailoverTransport::class, $transport);
        self::assertSame(DsnParser::DEFAULT_RETRY_PERIOD, $this->retryPeriodOf($transport));
    }

    public function test_one_insecure_member_rejects_the_whole_composite(): void
    {
        $this->expectRejection(
            'failover(smtps://u:p@a.example.com smtp://u:p@b.example.com:25)',
            MailerConfigurationException::OPPORTUNISTIC_TLS,
        );
    }

    public function test_an_insecure_member_nested_deep_inside_still_rejects_the_whole_composite(): void
    {
        $this->expectRejection(
            'failover(null://null roundrobin(null://null failover(smtp://u:p@deep.example.com:25)))',
            MailerConfigurationException::OPPORTUNISTIC_TLS,
        );
    }

    public function test_no_member_is_constructed_when_a_later_member_is_refused(): void
    {
        $this->expectRejection(
            'failover(null://null smtp://u:p@b.example.com:25)',
            MailerConfigurationException::OPPORTUNISTIC_TLS,
            $this->countingRegistry(),
        );

        self::assertSame(0, StubTransportFactory::$created, 'the whole tree is judged before the first leaf is built');
    }

    public function test_no_member_is_constructed_when_a_later_member_is_malformed(): void
    {
        $this->expectRejection(
            'failover(null://null smtp://mail..example.com)',
            MailerConfigurationException::LEAF_SHAPE,
            $this->countingRegistry(),
        );

        self::assertSame(0, StubTransportFactory::$created);
    }

    public function test_an_accepted_composite_does_construct_its_counted_member(): void
    {
        // The control for the two tests above: the count is of real
        // constructions, so a zero there is a judgement, not a stub
        // that never counts.
        $this->build('failover(null://null smtps://u:p@b.example.com)', $this->countingRegistry());

        self::assertSame(1, StubTransportFactory::$created);
    }

    // --- helpers --------------------------------------------------------------------------

    /**
     * The discard scheme built by the counting stub, beside the real
     * built-in SMTP entry.
     */
    private function countingRegistry(): TransportRegistry
    {
        StubTransportFactory::$create = static fn (): NullTransport => new NullTransport();

        return RegistryFixture::of(RegistryFixture::stubbedDiscard(), RegistryFixture::production('smtp'), RegistryFixture::production('smtps'));
    }

    private function streamOf(EsmtpTransport $transport): SocketStream
    {
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }

    private function retryPeriodOf(TransportInterface $transport): int
    {
        /** @var int $period */
        $period = new \ReflectionProperty(RoundRobinTransport::class, 'retryPeriod')->getValue($transport);

        return $period;
    }
}
