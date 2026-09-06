<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Policy;

use Kinetis\Mailer\Dsn\CompositeDsn;
use Kinetis\Mailer\Dsn\DsnNode;
use Kinetis\Mailer\Dsn\LeafDsn;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Registry\CredentialMode;
use Kinetis\Mailer\Registry\HostShape;
use Kinetis\Mailer\Registry\TransportEntry;
use Kinetis\Mailer\Registry\TransportKind;
use Kinetis\Mailer\Registry\TransportRegistry;
use Kinetis\Runtime\AppEnvironment;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * What a configured transport is allowed to be.
 *
 * {@see validate()} walks the complete tree first. Every member of every
 * `failover(...)` and `roundrobin(...)`, at every depth, is judged before
 * a single transport is constructed, so one insecure or malformed member
 * rejects the whole DSN with nothing built — a composite cannot hide a
 * plaintext relay behind a secure first choice.
 *
 * The rules:
 *
 * - **The scheme is in the registry or it is refused.**
 *   {@see TransportRegistry} names every admitted scheme, the exact
 *   official factory that builds it, and the exact class that factory
 *   may return. An unknown scheme, a future bridge, a custom factory and
 *   an impostor transport are all refused: before construction where the
 *   scheme is enough to tell, and immediately after it where the returned
 *   object is. Both comparisons are on the exact runtime class, so a
 *   subclass of a named transport is an impostor too.
 * - **Credentials match what the factory reads.** A missing half the
 *   factory requires, and a half it never reads, are both refused —
 *   except where a scheme's own factory documents an ambient credential
 *   mode, which Symfony's SES API branch does. Where the factory reads
 *   an optional pair, as `EsmtpTransportFactory` does, requiring one is
 *   the SMTP rule below rather than the registry's; and for that factory
 *   alone a half reading exactly `0` is refused as well, since it applies
 *   each half under a truthiness test and would configure nothing.
 * - **The authority matches what the factory reads.** A bridge SMTP
 *   branch that hard-codes its host and port refuses one in the DSN,
 *   rather than accepting a value with no effect.
 * - **SMTP is encrypted, authenticated and peer-verified.** `smtps://`
 *   carries implicit TLS; `smtp://` must ask for required STARTTLS with
 *   `require_tls=true`, since opportunistic TLS negotiates down to
 *   plaintext against a server that declines it and against anything
 *   able to strip the offer. `verify_peer=false` is refused outright —
 *   a bypassed certificate check is the one thing no profile relaxes.
 *   A provider SMTP scheme reaches the same servers over the same
 *   sockets, so a prefix buys no exemption; only bridges whose transport
 *   connects with implicit TLS or requires STARTTLS are in the registry
 *   at all.
 * - **An API transport reaches its provider over HTTPS.** The scheme is
 *   an admitted HTTPS one, and the client every API transport is handed
 *   refuses any URL that is not absolute HTTPS — see
 *   {@see \Kinetis\Mailer\NoRedirectHttpClient}.
 * - **`null://null` accepts and discards, in every environment.** It is
 *   never selected as a fallback: a DSN either names it or does not.
 * - **A local process needs `APP_ENV=development`.**
 *   `sendmail://default` runs a blocking binary, so it is
 *   development-only and may not name a command.
 *
 * The one exception is the **local-insecure profile**, selected with
 * `MAILER_ALLOW_INSECURE_LOCAL=true` (scoped per connection). It is valid
 * only when `AppEnvironment::detect()` reads Development out of the same
 * Config snapshot the DSN came from, and only for a DSN that is one
 * direct `smtp://` or `smtps://` leaf whose host is literally loopback.
 * It relaxes authentication, and for `smtp://` the required STARTTLS;
 * it never relaxes peer verification. Selecting it anywhere else is an
 * error in its own right rather than a flag that quietly does nothing: a
 * non-development `APP_ENV`, a routable host, a discard, API, provider
 * SMTP or sendmail leaf, and any composite at all — including one whose
 * members are all loopback — fail the boot naming the profile key.
 *
 * @internal to kinetis/mailer
 */
final readonly class TransportPolicy
{
    private function __construct(
        private string $key,
        private string $profileKey,
        private AppEnvironment $environment,
        private bool $allowInsecureLocal,
        private TransportRegistry $registry,
    ) {}

    /**
     * @param string $key        the DSN config key, quoted by every message
     * @param string $profileKey the local-insecure config key, quoted when
     *     selecting the profile is itself what failed
     *
     * @throws MailerConfigurationException
     */
    public static function for(
        string $key,
        string $profileKey,
        AppEnvironment $environment,
        bool $allowInsecureLocal,
        TransportRegistry $registry,
    ): self {
        if ($allowInsecureLocal && $environment !== AppEnvironment::Development) {
            throw MailerConfigurationException::rejected(
                $profileKey,
                MailerConfigurationException::INSECURE_LOCAL_IN_PRODUCTION,
            );
        }

        return new self($key, $profileKey, $environment, $allowInsecureLocal, $registry);
    }

    /**
     * @throws MailerConfigurationException
     */
    public function validate(#[\SensitiveParameter] DsnNode $node): void
    {
        if ($this->allowInsecureLocal) {
            $this->assertProfileApplies($node);
        }

        $this->walk($node);
    }

    /**
     * The entry a leaf resolves to, or a refusal. The one lookup every
     * other step is built on.
     *
     * @throws MailerConfigurationException
     */
    public function entryFor(#[\SensitiveParameter] LeafDsn $leaf): TransportEntry
    {
        return $this->registry->get($leaf->scheme)
            ?? throw $this->reject(MailerConfigurationException::UNSUPPORTED_SCHEME);
    }

    /**
     * The second half of the identity check, run on what the factory
     * returned rather than on what the DSN asked for. The comparison is
     * on the exact runtime class: `instanceof` would take a subclass of
     * the named transport, and a subclass is exactly the shape an
     * impostor takes.
     *
     * @throws MailerConfigurationException
     */
    public function assertBuilt(
        #[\SensitiveParameter] TransportEntry $entry,
        #[\SensitiveParameter] TransportInterface $transport,
    ): void {
        if (!in_array($transport::class, $entry->transportClasses, true)) {
            throw $this->reject(MailerConfigurationException::UNPROVABLE_FAMILY);
        }
    }

    /**
     * The profile covers one direct built-in SMTP leaf on loopback and
     * nothing else, so the check is on the whole DSN before any member
     * is looked at.
     */
    private function assertProfileApplies(#[\SensitiveParameter] DsnNode $node): void
    {
        if (!$node instanceof LeafDsn) {
            throw $this->rejectProfile();
        }

        $entry = $this->registry->get($node->scheme);

        if ($entry === null || $entry->kind !== TransportKind::CoreSmtp || !$node->hasLoopbackHost()) {
            throw $this->rejectProfile();
        }
    }

    private function walk(#[\SensitiveParameter] DsnNode $node): void
    {
        if ($node instanceof CompositeDsn) {
            foreach ($node->members as $member) {
                $this->walk($member);
            }

            return;
        }

        if ($node instanceof LeafDsn) {
            $this->validateLeaf($node);
        }
    }

    private function validateLeaf(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        $entry = $this->entryFor($leaf);

        $this->validateAuthority($leaf, $entry);
        $this->validateCredentials($leaf, $entry);
        $this->validateOptions($leaf, $entry);

        match ($entry->kind) {
            TransportKind::Discard => null,
            TransportKind::Sendmail => $this->validateSendmail($leaf),
            TransportKind::CoreSmtp => $this->validateCoreSmtp($leaf),
            TransportKind::ProviderSmtp => $this->validateProviderSmtp($leaf),
            TransportKind::Api => null,
        };
    }

    private function validateAuthority(#[\SensitiveParameter] LeafDsn $leaf, TransportEntry $entry): void
    {
        $shapeHolds = match ($entry->host) {
            HostShape::LiteralNull => $leaf->host === 'null',
            HostShape::LiteralDefault => $leaf->host === 'default',
            HostShape::Host => OptionRules::isHostname($leaf->host) || $leaf->isAddressLiteral(),
            HostShape::DefaultOrHost => $leaf->host === 'default'
                || OptionRules::isHostname($leaf->host)
                || $leaf->isAddressLiteral(),
        };

        if (!$shapeHolds || ($leaf->port !== null && !$entry->acceptsPort())) {
            throw $this->reject(MailerConfigurationException::WRONG_AUTHORITY);
        }
    }

    private function validateCredentials(#[\SensitiveParameter] LeafDsn $leaf, TransportEntry $entry): void
    {
        $holds = match ($entry->credentials) {
            CredentialMode::None => $leaf->user === null && $leaf->password === null,
            CredentialMode::UserOnly => $leaf->user !== null && $leaf->password === null,
            CredentialMode::UserAndPassword => $leaf->user !== null && $leaf->password !== null,
            CredentialMode::OptionalPair,
            CredentialMode::AmbientOrUserAndPassword => ($leaf->user === null) === ($leaf->password === null),
        };

        if (!$holds) {
            throw $this->reject(MailerConfigurationException::WRONG_CREDENTIALS);
        }
    }

    private function validateOptions(#[\SensitiveParameter] LeafDsn $leaf, TransportEntry $entry): void
    {
        foreach ($leaf->options as $key => $value) {
            if ($key === 'command' && $entry->kind === TransportKind::Sendmail) {
                throw $this->reject(MailerConfigurationException::SENDMAIL_COMMAND);
            }

            $rule = $entry->options[$key] ?? null;

            if ($rule === null) {
                throw $this->reject(MailerConfigurationException::UNKNOWN_OPTION);
            }

            if (!OptionRules::accepts($rule, $value)) {
                throw $this->reject(MailerConfigurationException::BAD_OPTION_VALUE);
            }
        }

        foreach ($entry->optionPrerequisites as $option => $needs) {
            if (isset($leaf->options[$option]) && !isset($leaf->options[$needs])) {
                throw $this->reject(MailerConfigurationException::MISSING_PREREQUISITE);
            }
        }
    }

    private function validateSendmail(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        if ($leaf->option('command') !== null) {
            throw $this->reject(MailerConfigurationException::SENDMAIL_COMMAND);
        }

        $this->requireDevelopment();
    }

    private function validateCoreSmtp(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        $this->assertPeerVerified($leaf);
        $this->assertNoHalfIsDropped($leaf);

        if ($this->allowInsecureLocal) {
            // assertProfileApplies() has already proven this is the one
            // direct loopback leaf the profile covers, and the profile
            // exists so a local mail catcher needs neither TLS nor an
            // account.
            return;
        }

        if ($leaf->user === null || $leaf->password === null) {
            throw $this->reject(MailerConfigurationException::WRONG_CREDENTIALS);
        }

        $autoTls = $leaf->option('auto_tls');

        if ($autoTls !== null && OptionRules::boolean($autoTls) !== true) {
            throw $this->reject(MailerConfigurationException::OPPORTUNISTIC_TLS);
        }

        if ($leaf->scheme === 'smtp' && OptionRules::boolean($leaf->option('require_tls') ?? '') !== true) {
            throw $this->reject(MailerConfigurationException::OPPORTUNISTIC_TLS);
        }
    }

    /**
     * `EsmtpTransportFactory` configures each half under a truthiness
     * test — `if ($user = $dsn->getUser())` — so a half reading exactly
     * `0`, written as `0` or as the escape `%30`, passes the grammar and
     * the arity check and configures nothing. The grammar has already
     * refused an empty half, which leaves this one value. The rule is the
     * built-in factory's alone: a bridge reads its halves through
     * `getUser()` and `getPassword()`, which refuse only null, and
     * configures `0` as the token it is.
     */
    private function assertNoHalfIsDropped(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        if ($leaf->user === '0' || $leaf->password === '0') {
            throw $this->reject(MailerConfigurationException::CREDENTIAL_DROPPED);
        }
    }

    /**
     * A provider SMTP transport in the registry already connects with
     * implicit TLS or requires STARTTLS, so what is left to check is that
     * an option cannot turn that off.
     */
    private function validateProviderSmtp(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        $this->assertPeerVerified($leaf);

        $requireTls = $leaf->option('require_tls');

        if ($requireTls !== null && OptionRules::boolean($requireTls) !== true) {
            throw $this->reject(MailerConfigurationException::OPPORTUNISTIC_TLS);
        }
    }

    private function assertPeerVerified(#[\SensitiveParameter] LeafDsn $leaf): void
    {
        $verifyPeer = $leaf->option('verify_peer');

        if ($verifyPeer !== null && OptionRules::boolean($verifyPeer) !== true) {
            throw $this->reject(MailerConfigurationException::PEER_VERIFICATION_BYPASS);
        }
    }

    private function requireDevelopment(): void
    {
        if ($this->environment !== AppEnvironment::Development) {
            throw $this->reject(MailerConfigurationException::LOCAL_ONLY);
        }
    }

    private function reject(string $reason): MailerConfigurationException
    {
        return MailerConfigurationException::rejected($this->key, $reason);
    }

    private function rejectProfile(): MailerConfigurationException
    {
        return MailerConfigurationException::rejected(
            $this->profileKey,
            MailerConfigurationException::LOOPBACK_ONLY,
        );
    }
}
