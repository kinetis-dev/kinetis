<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Mailer\Dsn\DsnParser;
use Kinetis\Mailer\Dsn\LeafDsn;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Registry\CredentialMode;
use Kinetis\Mailer\Registry\HostShape;
use Kinetis\Mailer\Registry\TransportEntry;
use Kinetis\Mailer\Registry\TransportKind;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Mailer\Tests\Doubles\FakeApiTransport;
use Kinetis\Mailer\Tests\Doubles\ImpostorTransport;
use Kinetis\Mailer\Tests\Doubles\OpenTransport;
use Kinetis\Mailer\Tests\Doubles\RegistryFixture;
use Kinetis\Mailer\Tests\Doubles\StubTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesApiAsyncAwsTransport;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesHttpAsyncAwsTransport;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesSmtpTransport;
use Symfony\Component\Mailer\Bridge\Google\Transport\GmailSmtpTransport;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridSmtpTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mime\Email;

final class TransportRegistryTest extends TestCase
{
    use AssemblesMailers;

    private const string API_DSN = RegistryFixture::API_SCHEME . '://KEY@default';

    private const string ALIASED_FACTORY = 'Kinetis\Mailer\Tests\Doubles\AliasedTransportFactory';

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

    // --- every production entry against its real factory ------------------------

    /**
     * One well-formed DSN per admitted scheme, the exact class the real
     * factory returns for it, and the built transport's own account of
     * where it connects. Every alias is listed separately rather than
     * folded into its sibling: `sendgrid` builds an SMTP transport while
     * `sendgrid+api` builds an API one, and `ses+smtp` reads options
     * `ses+api` does not.
     *
     * @return iterable<string, array{0: string, 1: class-string, 2: string}>
     */
    public static function admittedSchemes(): iterable
    {
        yield 'smtp' => ['smtp://user:pass@mail.example.com:587?require_tls=true', EsmtpTransport::class, 'smtp://mail.example.com:587'];
        yield 'smtps' => ['smtps://user:pass@mail.example.com', EsmtpTransport::class, 'smtps://mail.example.com'];
        yield 'null' => ['null://null', NullTransport::class, 'null://'];
        yield 'sendmail' => ['sendmail://default', SendmailTransport::class, 'smtp://sendmail'];
        yield 'sendmail+smtp' => ['sendmail+smtp://default', SendmailTransport::class, 'smtp://sendmail'];
        yield 'sendgrid+api' => ['sendgrid+api://KEY@default', SendgridApiTransport::class, 'sendgrid+api://api.sendgrid.com'];
        yield 'sendgrid' => ['sendgrid://KEY@default', SendgridSmtpTransport::class, 'smtps://smtp.sendgrid.net'];
        yield 'sendgrid+smtp' => ['sendgrid+smtp://KEY@default', SendgridSmtpTransport::class, 'smtps://smtp.sendgrid.net'];
        yield 'sendgrid+smtps' => ['sendgrid+smtps://KEY@default', SendgridSmtpTransport::class, 'smtps://smtp.sendgrid.net'];
        yield 'ses+api' => ['ses+api://KEYID:SECRET@default?region=eu-west-1', SesApiAsyncAwsTransport::class, 'ses+api://KEYID@eu-west-1'];
        yield 'ses+https' => ['ses+https://KEYID:SECRET@default?region=eu-west-1', SesHttpAsyncAwsTransport::class, 'ses+https://KEYID@eu-west-1'];
        yield 'ses' => ['ses://KEYID:SECRET@default?region=eu-west-1', SesHttpAsyncAwsTransport::class, 'ses+https://KEYID@eu-west-1'];
        yield 'ses+smtp' => ['ses+smtp://USER:PASS@default?region=eu-west-1', SesSmtpTransport::class, 'smtps://email-smtp.eu-west-1.amazonaws.com'];
        yield 'ses+smtps' => ['ses+smtps://USER:PASS@default?region=eu-west-1', SesSmtpTransport::class, 'smtps://email-smtp.eu-west-1.amazonaws.com'];
        yield 'mailgun+api' => ['mailgun+api://KEY:example.org@default?region=eu', MailgunApiTransport::class, 'mailgun+api://api.eu.mailgun.net?domain=example.org'];
        yield 'mailgun+https' => ['mailgun+https://KEY:example.org@default', MailgunHttpTransport::class, 'mailgun+https://api.mailgun.net?domain=example.org'];
        yield 'mailgun' => ['mailgun://KEY:example.org@default', MailgunHttpTransport::class, 'mailgun+https://api.mailgun.net?domain=example.org'];
        yield 'postmark+api' => ['postmark+api://TOKEN@default?message_stream=broadcast', PostmarkApiTransport::class, 'postmark+api://api.postmarkapp.com?message_stream=broadcast'];
        yield 'gmail' => ['gmail://user:pass@default', GmailSmtpTransport::class, 'smtps://smtp.gmail.com'];
        yield 'gmail+smtp' => ['gmail+smtp://user:pass@default', GmailSmtpTransport::class, 'smtps://smtp.gmail.com'];
        yield 'gmail+smtps' => ['gmail+smtps://user:pass@default', GmailSmtpTransport::class, 'smtps://smtp.gmail.com'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('admittedSchemes')]
    public function test_every_admitted_scheme_is_built_by_its_own_factory_as_exactly_the_class_its_row_names(
        string $dsn,
        string $class,
        string $endpoint,
    ): void {
        $leaf = DsnParser::parse($dsn, self::DSN_KEY);
        self::assertInstanceOf(LeafDsn::class, $leaf);

        $entry = TransportRegistry::supported()->get($leaf->scheme);
        self::assertInstanceOf(TransportEntry::class, $entry);
        self::assertContains($class, $entry->transportClasses);

        $factory = new ($entry->factoryClass)(null, new MockHttpClient(), null);
        self::assertInstanceOf(TransportFactoryInterface::class, $factory);
        self::assertSame($entry->factoryClass, $factory::class);
        self::assertTrue($factory->supports($leaf->toSymfonyDsn()), 'the official factory claims the scheme');

        $transport = $this->buildTransport($dsn);

        self::assertSame($class, $transport::class);
        self::assertSame($endpoint, (string) $transport);
    }

    public function test_the_provider_list_covers_exactly_the_admitted_schemes(): void
    {
        $listed = array_keys(iterator_to_array(self::admittedSchemes()));

        self::assertSame(TransportRegistry::supported()->schemes(), $listed);
    }

    public function test_every_entry_names_a_factory_a_transport_and_a_package(): void
    {
        $registry = TransportRegistry::supported();

        foreach ($registry->schemes() as $scheme) {
            $entry = $registry->get($scheme);
            self::assertInstanceOf(TransportEntry::class, $entry);
            self::assertSame($scheme, $entry->scheme);
            self::assertStringStartsWith('Symfony\Component\Mailer\\', $entry->factoryClass);
            self::assertNotSame([], $entry->transportClasses);
            self::assertMatchesRegularExpression('#^symfony/[a-z-]+$#', $entry->package);
        }
    }

    // --- credentials, hosts and options reach the built transport ---------------

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function smtpCredentials(): iterable
    {
        yield 'smtps' => ['smtps://alice:s3cr%40t@mail.example.com', 'alice', 's3cr@t'];
        yield 'smtp with required STARTTLS' => ['smtp://alice:pass@mail.example.com:587?require_tls=true', 'alice', 'pass'];
        yield 'sendgrid+smtp' => ['sendgrid+smtp://SG.key@default', 'apikey', 'SG.key'];
        yield 'ses+smtp' => ['ses+smtp://AKIAEXAMPLE:wJalrXUtnFEMI@default?region=eu-west-1', 'AKIAEXAMPLE', 'wJalrXUtnFEMI'];
        yield 'gmail' => ['gmail://someone:app-password@default', 'someone', 'app-password'];
    }

    #[DataProvider('smtpCredentials')]
    public function test_an_smtp_family_transport_holds_both_credential_halves(string $dsn, string $user, string $password): void
    {
        $transport = $this->buildTransport($dsn);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame($user, $transport->getUsername());
        self::assertSame($password, $transport->getPassword());
    }

    public function test_core_smtp_options_reach_the_transport(): void
    {
        $transport = $this->buildTransport(
            'smtp://u:p@mail.example.com:587?require_tls=true&auto_tls=true&local_domain=relay.example.com',
        );

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertTrue($transport->isTlsRequired());
        self::assertTrue($transport->isAutoTls());
        self::assertSame('relay.example.com', $transport->getLocalDomain());

        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('mail.example.com', $stream->getHost());
        self::assertSame(587, $stream->getPort());
    }

    public function test_ses_smtp_reads_the_options_its_own_branch_reads(): void
    {
        $transport = $this->buildTransport('ses+smtp://USER:PASS@default?region=us-east-1&require_tls=true&ping_threshold=30');

        self::assertInstanceOf(SesSmtpTransport::class, $transport);
        self::assertSame('smtps://email-smtp.us-east-1.amazonaws.com', (string) $transport);
        self::assertTrue($transport->isTlsRequired());

        $unset = $this->buildTransport('ses+smtp://USER:PASS@default?region=us-east-1');
        self::assertInstanceOf(SesSmtpTransport::class, $unset);
        self::assertFalse($unset->isTlsRequired(), 'on 465 the flag is only what the option says');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function customEndpoints(): iterable
    {
        yield 'sendgrid+api' => ['sendgrid+api://KEY@api.eu.example.com:8443', 'sendgrid+api://api.eu.example.com:8443'];
        yield 'ses+api' => ['ses+api://KEYID:SECRET@ses.internal.example.com:8443?region=eu-west-1', 'ses+api://KEYID@ses.internal.example.com:8443'];
        yield 'ses+https' => ['ses+https://KEYID:SECRET@ses.internal.example.com?region=eu-west-1', 'ses+https://KEYID@ses.internal.example.com'];
        yield 'ses+smtp' => ['ses+smtp://USER:PASS@relay.example.com:2465?region=eu-west-1', 'smtps://relay.example.com:2465'];
        yield 'mailgun+api' => ['mailgun+api://KEY:example.org@mg.example.com', 'mailgun+api://mg.example.com?domain=example.org'];
        yield 'postmark+api' => ['postmark+api://TOKEN@pm.example.com:8443', 'postmark+api://pm.example.com:8443'];
    }

    #[DataProvider('customEndpoints')]
    public function test_a_scheme_that_reads_a_host_connects_to_the_custom_endpoint(string $dsn, string $endpoint): void
    {
        self::assertSame($endpoint, (string) $this->buildTransport($dsn));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function regions(): iterable
    {
        yield 'sendgrid+api' => ['sendgrid+api://KEY@default?region=eu', 'sendgrid+api://api.eu.sendgrid.com'];
        yield 'sendgrid+smtp' => ['sendgrid+smtp://KEY@default?region=eu', 'smtps://smtp.eu.sendgrid.net'];
        yield 'ses+api' => ['ses+api://KEYID:SECRET@default?region=us-east-1', 'ses+api://KEYID@us-east-1'];
        yield 'ses+smtps' => ['ses+smtps://USER:PASS@default?region=us-east-1', 'smtps://email-smtp.us-east-1.amazonaws.com'];
        yield 'mailgun' => ['mailgun://KEY:example.org@default?region=eu', 'mailgun+https://api.eu.mailgun.net?domain=example.org'];
    }

    #[DataProvider('regions')]
    public function test_region_selects_the_provider_host(string $dsn, string $endpoint): void
    {
        self::assertSame($endpoint, (string) $this->buildTransport($dsn));
    }

    public function test_a_sendgrid_api_send_carries_the_key_as_a_bearer_token(): void
    {
        $request = $this->sendThrough(
            'sendgrid+api://SG.sentinel@default',
            new MockResponse('', ['http_code' => 202, 'response_headers' => ['x-message-id' => 'sg-1']]),
        );

        self::assertSame('https://api.sendgrid.com/v3/mail/send', $request['url']);
        self::assertStringContainsStringIgnoringCase('authorization: Bearer SG.sentinel', $request['headers']);
    }

    public function test_a_mailgun_send_carries_the_key_as_basic_auth_and_the_domain_in_the_path(): void
    {
        $api = $this->sendThrough('mailgun+api://KEY:example.org@default?region=eu', self::json('{"id":"<1@example.org>","message":"Queued"}'));

        self::assertSame('https://api.eu.mailgun.net/v3/example.org/messages', $api['url']);
        self::assertStringContainsStringIgnoringCase('authorization: Basic ' . base64_encode('api:KEY'), $api['headers']);

        $raw = $this->sendThrough('mailgun+https://KEY:example.org@default', self::json('{"id":"<2@example.org>","message":"Queued"}'));

        self::assertSame('https://api.mailgun.net/v3/example.org/messages.mime', $raw['url']);
        self::assertStringContainsStringIgnoringCase('authorization: Basic ' . base64_encode('api:KEY'), $raw['headers']);
    }

    public function test_a_postmark_send_carries_the_server_token_and_the_message_stream(): void
    {
        $request = $this->sendThrough(
            'postmark+api://TOKEN@default?message_stream=broadcast',
            self::json('{"MessageID":"pm-1","ErrorCode":0,"Message":"OK"}'),
        );

        self::assertSame('https://api.postmarkapp.com/email', $request['url']);
        self::assertStringContainsStringIgnoringCase('x-postmark-server-token: TOKEN', $request['headers']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('broadcast', $payload['MessageStream']);
    }

    public function test_an_ses_send_is_signed_with_the_configured_pair_and_carries_the_session_token(): void
    {
        $api = $this->sendThrough(
            'ses+api://AKIASENTINEL:SECRET@default?region=eu-west-1&session_token=FQoGZXIvYXdz',
            self::json('{"MessageId":"ses-1"}'),
        );

        self::assertStringStartsWith('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $api['url']);
        self::assertStringContainsStringIgnoringCase('authorization: AWS4-HMAC-SHA256 Credential=AKIASENTINEL/', $api['headers']);
        self::assertStringContainsStringIgnoringCase('x-amz-security-token: FQoGZXIvYXdz', $api['headers']);

        $raw = $this->sendThrough('ses+https://AKIASENTINEL:SECRET@default?region=eu-west-1', self::json('{"MessageId":"ses-2"}'));

        self::assertStringStartsWith('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $raw['url']);
        self::assertStringContainsStringIgnoringCase('Credential=AKIASENTINEL/', $raw['headers']);
        self::assertStringNotContainsStringIgnoringCase('x-amz-security-token', $raw['headers']);
    }

    public function test_the_ses_api_branch_accepts_the_ambient_aws_credential_chain(): void
    {
        $transport = $this->buildTransport('ses+api://default?region=eu-west-1');

        self::assertInstanceOf(SesApiAsyncAwsTransport::class, $transport);
        self::assertSame('ses+api://@eu-west-1', (string) $transport, 'no key id is configured; the chain resolves one at send time');
    }

    // --- refusals ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedSchemes(): iterable
    {
        yield 'a scheme nothing supports' => ['carrierpigeon://default'];
        yield 'a bridge outside the registry' => ['mailjet+api://KEY:SECRET@default'];
        yield 'a plaintext API alias' => ['sendgrid+http://KEY@default'];
        yield 'an opportunistic provider SMTP branch' => ['mailgun+smtp://KEY:example.org@default'];
        yield 'another opportunistic provider SMTP branch' => ['postmark+smtp://TOKEN@default'];
        yield 'a future bridge' => ['someprovider+api://KEY@default'];
        yield 'an unadmitted alias of an admitted bridge' => ['postmark://TOKEN@default'];
        yield 'a scheme whose family php.ini decides at construction' => ['native://default'];
    }

    #[DataProvider('refusedSchemes')]
    public function test_a_scheme_outside_the_registry_is_refused(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::UNSUPPORTED_SCHEME);
    }

    public function test_an_admitted_scheme_whose_bridge_is_absent_names_the_package_to_install(): void
    {
        // A factory class that exists in no installed package, so the
        // outcome does not depend on which bridges this suite happens
        // to have.
        $registry = RegistryFixture::of(new TransportEntry(
            'example+api',
            TransportKind::Api,
            'Kinetis\Mailer\Tests\Doubles\NotInstalledTransportFactory',
            [FakeApiTransport::class],
            'symfony/example-mailer',
            CredentialMode::UserOnly,
            HostShape::DefaultOrHost,
        ));

        $exception = $this->expectRejection('example+api://KEY@default', MailerConfigurationException::MISSING_BRIDGE, $registry);

        self::assertStringContainsString('symfony/example-mailer', $exception->getMessage());
    }

    public function test_a_registry_entry_naming_a_class_that_is_not_a_factory_is_refused(): void
    {
        $registry = RegistryFixture::of(RegistryFixture::stubbedApi(factoryClass: NullTransport::class));

        $this->expectRejection(self::API_DSN, MailerConfigurationException::WRONG_FACTORY, $registry);
    }

    public function test_an_alias_resolving_to_another_class_than_the_named_factory_is_refused(): void
    {
        if (!class_exists(self::ALIASED_FACTORY, false)) {
            class_alias(StubTransportFactory::class, self::ALIASED_FACTORY);
        }

        StubTransportFactory::$create = static fn (): FakeApiTransport => new FakeApiTransport(new MockHttpClient());
        $registry = RegistryFixture::of(RegistryFixture::stubbedApi(factoryClass: self::ALIASED_FACTORY));

        $this->expectRejection(self::API_DSN, MailerConfigurationException::WRONG_FACTORY, $registry);

        self::assertSame(0, StubTransportFactory::$created, 'nothing was built through the alias');
    }

    public function test_a_factory_that_declines_the_scheme_it_was_chosen_for_is_refused(): void
    {
        StubTransportFactory::$supports = static fn (): bool => false;

        $this->expectRejection(self::API_DSN, MailerConfigurationException::WRONG_FACTORY, RegistryFixture::of(RegistryFixture::stubbedApi()));
    }

    public function test_a_subclass_of_the_named_transport_is_refused_where_the_class_itself_is_accepted(): void
    {
        $registry = RegistryFixture::of(RegistryFixture::stubbedApi(transportClasses: [OpenTransport::class]));

        StubTransportFactory::$create = static fn (): OpenTransport => new OpenTransport();
        $accepted = $this->buildTransport(self::API_DSN, $registry);
        self::assertSame(OpenTransport::class, $accepted::class);

        // An instance of the named class, by inheritance, and not it.
        StubTransportFactory::$create = static fn (): ImpostorTransport => new ImpostorTransport();
        $this->expectRejection(self::API_DSN, MailerConfigurationException::UNPROVABLE_FAMILY, $registry);
    }

    public function test_an_impostor_http_transport_is_refused(): void
    {
        // An AbstractHttpTransport that is not the class this scheme
        // resolves to. A family check would take it.
        StubTransportFactory::$create = static fn (): FakeApiTransport => new FakeApiTransport(new MockHttpClient());
        $registry = RegistryFixture::of(RegistryFixture::stubbedApi(transportClasses: [SendgridApiTransport::class]));

        $this->expectRejection(self::API_DSN, MailerConfigurationException::UNPROVABLE_FAMILY, $registry);
    }

    public function test_a_transport_of_a_different_admitted_class_is_refused(): void
    {
        StubTransportFactory::$create = static fn (): NullTransport => new NullTransport();

        $this->expectRejection(self::API_DSN, MailerConfigurationException::UNPROVABLE_FAMILY, RegistryFixture::of(RegistryFixture::stubbedApi()));
    }

    // --- credentials ----------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrongCredentials(): iterable
    {
        yield 'a token-only scheme given a password' => ['sendgrid+api://KEY:EXTRA@default'];
        yield 'a token-only scheme given nothing' => ['sendgrid+api://default'];
        yield 'a pair scheme given only a user' => ['gmail+smtp://user@default'];
        yield 'a pair scheme given nothing' => ['gmail+smtp://default'];
        yield 'a credential-free scheme given one' => ['null://KEY@null'];
        yield 'a sendmail scheme given a credential' => ['sendmail://user:pass@default'];
        yield 'the ambient scheme given only half a pair' => ['ses+api://KEYID@default?region=eu-west-1'];
        yield 'a provider SMTP scheme given only a token' => ['ses+smtp://USER@default?region=eu-west-1'];
    }

    #[DataProvider('wrongCredentials')]
    public function test_credential_arity_matches_what_the_factory_reads(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::WRONG_CREDENTIALS);
    }

    // --- authority ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrongAuthorities(): iterable
    {
        yield 'the discard host' => ['null://default'];
        yield 'a discard port' => ['null://null:25'];
        yield 'a sendmail host' => ['sendmail://mail.example.com'];
        yield 'a sendmail port' => ['sendmail://default:25'];
        yield 'a hard-coded provider SMTP host' => ['gmail+smtp://user:pass@smtp.gmail.com'];
        yield 'a hard-coded provider SMTP port' => ['sendgrid+smtp://KEY@default:465'];
    }

    #[DataProvider('wrongAuthorities')]
    public function test_authority_shape_matches_what_the_factory_reads(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::WRONG_AUTHORITY);
    }

    // --- options ----------------------------------------------------------------------

    public function test_ses_takes_session_token_only_on_its_api_branch(): void
    {
        foreach (['ses+smtp', 'ses+smtps'] as $scheme) {
            $this->expectRejection(
                "{$scheme}://USER:PASS@default?region=eu-west-1&session_token=FQoGZXIvYXdz",
                MailerConfigurationException::UNKNOWN_OPTION,
            );
        }
    }

    public function test_a_core_smtp_option_is_not_available_on_a_provider_scheme(): void
    {
        // EsmtpTransportFactory reads local_domain; SesTransportFactory
        // does not, so accepting it would accept an option nothing
        // applies.
        $this->expectRejection(
            'ses+smtp://USER:PASS@default?region=eu-west-1&local_domain=relay.example.com',
            MailerConfigurationException::UNKNOWN_OPTION,
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedOptionValues(): iterable
    {
        yield 'a region carrying a dot' => ['sendgrid+api://KEY@default?region=eu.attacker.example'];
        yield 'a region carrying a hyphen at the edge' => ['sendgrid+api://KEY@default?region=-eu'];
        yield 'an uppercase AWS region' => ['ses+api://KEYID:SECRET@default?region=EU-WEST-1'];
        yield 'an AWS region carrying a dot' => ['ses+api://KEYID:SECRET@default?region=eu.west.1'];
        yield 'a message stream carrying a dot' => ['postmark+api://TOKEN@default?message_stream=a.b'];
        yield 'a session token carrying a colon' => ['ses+api://KEYID:SECRET@default?region=eu-west-1&session_token=a:b'];
    }

    #[DataProvider('refusedOptionValues')]
    public function test_an_option_value_outside_its_own_grammar_is_refused(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::BAD_OPTION_VALUE);
    }

    // --- helpers ----------------------------------------------------------------------

    private static function json(string $body): MockResponse
    {
        return new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * Sends one message through the transport the DSN builds, over a
     * client that records the single request the bridge makes.
     *
     * @return array{url: string, headers: string, body: string}
     */
    private function sendThrough(string $dsn, MockResponse $response): array
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $response): MockResponse {
            $headers = [];

            foreach ($options['headers'] ?? [] as $header) {
                $headers[] = is_array($header) ? implode("\n", $header) : (string) $header;
            }

            $body = $options['body'] ?? '';
            $seen = [
                'method' => $method,
                'url' => $url,
                'headers' => implode("\n", $headers),
                'body' => is_string($body) ? $body : '',
            ];

            return $response;
        });

        $this->build($dsn, client: $client)->send(
            new Email()
                ->from('noreply@example.com')
                ->to('user@example.com')
                ->subject('Registry')
                ->text('One send through a recording client.'),
        );

        self::assertIsArray($seen, 'the transport made a request');
        self::assertSame('POST', $seen['method']);

        return $seen;
    }
}
