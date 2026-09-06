<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Registry;

use Kinetis\Mailer\Policy\OptionRules;

/**
 * The closed set of transports `kinetis/mailer` will build, and the only
 * thing that decides whether a scheme is supported.
 *
 * Every entry was read off the pinned Symfony 8.1 factory that builds it,
 * because nothing about a bridge can be inferred: `SesHttpAsyncAwsTransport`
 * extends `AbstractTransport` rather than `AbstractHttpTransport`, so a
 * family check would reject it; `MailgunSmtpTransport` and
 * `PostmarkSmtpTransport` connect on port 587 with `$tls = false` and
 * never call `setRequireTls()`, so their SMTP schemes negotiate
 * opportunistic TLS and are left out entirely; and which options a
 * factory reads differs branch by branch inside one bridge — Symfony's
 * SES factory reads `session_token` only in its API branch.
 *
 * A scheme absent from this table is refused. An unknown scheme, a future
 * bridge, a custom factory registered under a familiar name, and a
 * factory whose class does not match the one named here all land in the
 * same place, before any construction.
 *
 * `native` is absent for a different reason. `NativeTransportFactory`
 * decides the transport family at construction, from php.ini: a local
 * sendmail process where `sendmail_path` is set, and on Windows a raw
 * SMTP socket to whatever host `SMTP` and `smtp_port` name. A family
 * that is not known until the object exists cannot be judged before it
 * is built, and every rule here runs before construction.
 *
 * Adding a transport means reading its factory and writing the row, which
 * is the cost of the guarantee rather than an obstacle to it. Providers
 * whose SMTP branch is opportunistic can be admitted the day Symfony
 * requires TLS there.
 *
 * Constructing a registry is not a consumer facility. The public
 * {@see \Kinetis\Mailer\MailerFactory} builds over {@see supported()}
 * and nothing else; the constructor exists so the package's own tests
 * can drive {@see \Kinetis\Mailer\MailerAssembler} over entries that
 * name test factories.
 *
 * @internal to kinetis/mailer
 */
final readonly class TransportRegistry
{
    private const string SYMFONY_MAILER = 'symfony/mailer';

    private const string ESMTP_FACTORY = 'Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory';

    private const string ESMTP_TRANSPORT = 'Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport';

    /**
     * Every option `EsmtpTransportFactory::create()` reads, and nothing
     * else.
     *
     * @var array<string, string>
     */
    private const array CORE_SMTP_OPTIONS = [
        'auto_tls' => OptionRules::BOOL,
        'require_tls' => OptionRules::BOOL,
        'source_ip' => OptionRules::SOURCE_IP,
        'verify_peer' => OptionRules::BOOL,
        'peer_fingerprint' => OptionRules::FINGERPRINT,
        'local_domain' => OptionRules::DOMAIN,
        'max_per_second' => OptionRules::RATE,
        'restart_threshold' => OptionRules::COUNT,
        'restart_threshold_sleep' => OptionRules::SLEEP_SECONDS,
        'ping_threshold' => OptionRules::SECONDS,
    ];

    /**
     * `restart_threshold_sleep` reaches the transport only as the second
     * argument of the `setRestartThreshold()` call the factory makes when
     * the threshold is set, so on its own it is inert.
     *
     * @var array<string, string>
     */
    private const array CORE_SMTP_PREREQUISITES = ['restart_threshold_sleep' => 'restart_threshold'];

    /** @var array<string, TransportEntry> */
    private array $entries;

    /**
     * @param list<TransportEntry> $entries
     */
    public function __construct(array $entries)
    {
        $keyed = [];

        foreach ($entries as $entry) {
            $keyed[$entry->scheme] = $entry;
        }

        $this->entries = $keyed;
    }

    public function has(string $scheme): bool
    {
        return isset($this->entries[$scheme]);
    }

    public function get(string $scheme): ?TransportEntry
    {
        return $this->entries[$scheme] ?? null;
    }

    /**
     * @return list<string>
     */
    public function schemes(): array
    {
        return array_keys($this->entries);
    }

    public static function supported(): self
    {
        return new self([...self::core(), ...self::sendgrid(), ...self::ses(), ...self::mailgun(), ...self::postmark(), ...self::gmail()]);
    }

    /**
     * @return list<TransportEntry>
     */
    private static function core(): array
    {
        return [
            new TransportEntry(
                'smtp',
                TransportKind::CoreSmtp,
                self::ESMTP_FACTORY,
                [self::ESMTP_TRANSPORT],
                self::SYMFONY_MAILER,
                CredentialMode::OptionalPair,
                HostShape::Host,
                self::CORE_SMTP_OPTIONS,
                self::CORE_SMTP_PREREQUISITES,
            ),
            new TransportEntry(
                'smtps',
                TransportKind::CoreSmtp,
                self::ESMTP_FACTORY,
                [self::ESMTP_TRANSPORT],
                self::SYMFONY_MAILER,
                CredentialMode::OptionalPair,
                HostShape::Host,
                self::CORE_SMTP_OPTIONS,
                self::CORE_SMTP_PREREQUISITES,
            ),
            new TransportEntry(
                'null',
                TransportKind::Discard,
                'Symfony\Component\Mailer\Transport\NullTransportFactory',
                ['Symfony\Component\Mailer\Transport\NullTransport'],
                self::SYMFONY_MAILER,
                CredentialMode::None,
                HostShape::LiteralNull,
            ),
            new TransportEntry(
                'sendmail',
                TransportKind::Sendmail,
                'Symfony\Component\Mailer\Transport\SendmailTransportFactory',
                ['Symfony\Component\Mailer\Transport\SendmailTransport'],
                self::SYMFONY_MAILER,
                CredentialMode::None,
                HostShape::LiteralDefault,
            ),
            new TransportEntry(
                'sendmail+smtp',
                TransportKind::Sendmail,
                'Symfony\Component\Mailer\Transport\SendmailTransportFactory',
                ['Symfony\Component\Mailer\Transport\SendmailTransport'],
                self::SYMFONY_MAILER,
                CredentialMode::None,
                HostShape::LiteralDefault,
            ),
        ];
    }

    /**
     * `SendgridTransportFactory` reads one token in the user position and
     * `region` in both branches, and its SMTP transport connects on 465
     * with implicit TLS. `region` is interpolated into a hostname
     * (`smtp.%s.sendgrid.net`, `api.%s.sendgrid.com`), so it is one DNS
     * label rather than free text.
     *
     * @return list<TransportEntry>
     */
    private static function sendgrid(): array
    {
        $factory = 'Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory';
        $package = 'symfony/sendgrid-mailer';
        $options = ['region' => OptionRules::DNS_LABEL];
        $smtp = 'Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridSmtpTransport';

        return [
            new TransportEntry('sendgrid+api', TransportKind::Api, $factory, ['Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport'], $package, CredentialMode::UserOnly, HostShape::DefaultOrHost, $options),
            new TransportEntry('sendgrid', TransportKind::ProviderSmtp, $factory, [$smtp], $package, CredentialMode::UserOnly, HostShape::LiteralDefault, $options),
            new TransportEntry('sendgrid+smtp', TransportKind::ProviderSmtp, $factory, [$smtp], $package, CredentialMode::UserOnly, HostShape::LiteralDefault, $options),
            new TransportEntry('sendgrid+smtps', TransportKind::ProviderSmtp, $factory, [$smtp], $package, CredentialMode::UserOnly, HostShape::LiteralDefault, $options),
        ];
    }

    /**
     * `SesTransportFactory` splits sharply. Its API branch builds an
     * AsyncAws `Configuration` from `region`, the credential pair and
     * `session_token`, and accepts a custom endpoint host; its SMTP
     * branch builds an `EsmtpTransport` on 465 (or required STARTTLS on
     * any other port) from the credential pair, `region`, `require_tls`
     * and `ping_threshold`, and reads no `session_token` at all.
     *
     * @return list<TransportEntry>
     */
    private static function ses(): array
    {
        $factory = 'Symfony\Component\Mailer\Bridge\Amazon\Transport\SesTransportFactory';
        $package = 'symfony/amazon-mailer';
        $http = 'Symfony\Component\Mailer\Bridge\Amazon\Transport\SesHttpAsyncAwsTransport';
        $apiOptions = ['region' => OptionRules::AWS_REGION, 'session_token' => OptionRules::OPAQUE_TOKEN];
        $smtpOptions = [
            'region' => OptionRules::AWS_REGION,
            'require_tls' => OptionRules::BOOL,
            'ping_threshold' => OptionRules::SECONDS,
        ];
        $smtp = 'Symfony\Component\Mailer\Bridge\Amazon\Transport\SesSmtpTransport';

        return [
            new TransportEntry('ses+api', TransportKind::Api, $factory, ['Symfony\Component\Mailer\Bridge\Amazon\Transport\SesApiAsyncAwsTransport'], $package, CredentialMode::AmbientOrUserAndPassword, HostShape::DefaultOrHost, $apiOptions),
            new TransportEntry('ses+https', TransportKind::Api, $factory, [$http], $package, CredentialMode::AmbientOrUserAndPassword, HostShape::DefaultOrHost, $apiOptions),
            new TransportEntry('ses', TransportKind::Api, $factory, [$http], $package, CredentialMode::AmbientOrUserAndPassword, HostShape::DefaultOrHost, $apiOptions),
            new TransportEntry('ses+smtp', TransportKind::ProviderSmtp, $factory, [$smtp], $package, CredentialMode::UserAndPassword, HostShape::DefaultOrHost, $smtpOptions),
            new TransportEntry('ses+smtps', TransportKind::ProviderSmtp, $factory, [$smtp], $package, CredentialMode::UserAndPassword, HostShape::DefaultOrHost, $smtpOptions),
        ];
    }

    /**
     * Only Mailgun's HTTP branches are admitted.
     * `MailgunSmtpTransport::__construct()` connects on 587 with
     * `$tls = false` and never requires STARTTLS, which is the
     * opportunistic downgrade this package refuses everywhere else.
     *
     * @return list<TransportEntry>
     */
    private static function mailgun(): array
    {
        $factory = 'Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory';
        $package = 'symfony/mailgun-mailer';
        $options = ['region' => OptionRules::DNS_LABEL];
        $http = 'Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport';

        return [
            new TransportEntry('mailgun+api', TransportKind::Api, $factory, ['Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport'], $package, CredentialMode::UserAndPassword, HostShape::DefaultOrHost, $options),
            new TransportEntry('mailgun+https', TransportKind::Api, $factory, [$http], $package, CredentialMode::UserAndPassword, HostShape::DefaultOrHost, $options),
            new TransportEntry('mailgun', TransportKind::Api, $factory, [$http], $package, CredentialMode::UserAndPassword, HostShape::DefaultOrHost, $options),
        ];
    }

    /**
     * Postmark's SMTP branch connects on 587 with `$tls = false`, so only
     * the API scheme is admitted. `message_stream` becomes a payload
     * field and an unstructured MIME header, which is why its rule is an
     * identifier rather than free text.
     *
     * @return list<TransportEntry>
     */
    private static function postmark(): array
    {
        return [
            new TransportEntry(
                'postmark+api',
                TransportKind::Api,
                'Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory',
                ['Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport'],
                'symfony/postmark-mailer',
                CredentialMode::UserOnly,
                HostShape::DefaultOrHost,
                ['message_stream' => OptionRules::IDENTIFIER],
            ),
        ];
    }

    /**
     * `GmailTransportFactory` reads the credential pair and nothing else,
     * and its transport connects to `smtp.gmail.com:465` with implicit
     * TLS regardless of what the DSN's authority says.
     *
     * @return list<TransportEntry>
     */
    private static function gmail(): array
    {
        $factory = 'Symfony\Component\Mailer\Bridge\Google\Transport\GmailTransportFactory';
        $package = 'symfony/google-mailer';
        $transport = ['Symfony\Component\Mailer\Bridge\Google\Transport\GmailSmtpTransport'];

        return [
            new TransportEntry('gmail', TransportKind::ProviderSmtp, $factory, $transport, $package, CredentialMode::UserAndPassword, HostShape::LiteralDefault),
            new TransportEntry('gmail+smtp', TransportKind::ProviderSmtp, $factory, $transport, $package, CredentialMode::UserAndPassword, HostShape::LiteralDefault),
            new TransportEntry('gmail+smtps', TransportKind::ProviderSmtp, $factory, $transport, $package, CredentialMode::UserAndPassword, HostShape::LiteralDefault),
        ];
    }
}
