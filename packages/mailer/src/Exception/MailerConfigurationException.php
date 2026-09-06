<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Exception;

use RuntimeException;

/**
 * The one failure type every mailer configuration path ends in: a missing
 * key, a DSN the {@see \Kinetis\Mailer\Dsn\DsnParser} grammar refuses, a
 * transport {@see \Kinetis\Mailer\Policy\TransportPolicy} refuses, and any
 * Config or Symfony exception raised underneath — all normalized to this,
 * thrown fresh with no `$previous`.
 *
 * Nothing derived from the configured value ever reaches a message. The
 * only interpolated value is the config key itself, and a key is built
 * from a connection name {@see \Kinetis\Mailer\ConnectionName} has already
 * confined to one bounded lowercase grammar. A reason is always one of
 * this class's own literals, so a DSN, a username, a password, an API
 * token, a query string, or a provider credential cannot appear in the
 * message, in `__toString()`, in a serialized copy, or in an ordinary log
 * line quoting any of them.
 *
 * Dropping `$previous` is the point rather than a loss: a Symfony
 * `InvalidArgumentException` from a transport factory quotes the DSN it
 * was handed, and a chained exception is rendered in full by
 * `__toString()` and by every logger that formats one. The rule that
 * makes this class safe is the one it enforces on itself — it carries no
 * cause at all.
 */
final class MailerConfigurationException extends RuntimeException
{
    public const string NOT_SET = 'no DSN is configured for this connection';

    public const string EMPTY_VALUE = 'the value is empty';

    public const string TOO_LONG = 'the DSN exceeds the 8192-byte limit';

    public const string TOO_DEEP = 'the transport list nests deeper than 8 levels';

    public const string TOO_MANY_LEAVES = 'the transport list holds more than 16 leaf transports';

    public const string TOO_MANY_QUERY_PAIRS = 'a leaf DSN carries more than 32 query pairs';

    public const string VALUE_TOO_LONG = 'a credential or option value exceeds 1024 bytes';

    public const string BAD_COMPOSITE = 'failover(...) and roundrobin(...) take one or more members separated by exactly one ASCII space and closed by ")"';

    public const string EMPTY_MEMBER = 'a composite member is empty';

    public const string TRAILING_GARBAGE = 'characters follow the end of the transport list';

    public const string BAD_RETRY_PERIOD = 'retry_period is a composite-only option written once as "?retry_period=N", with N from 1 to 86400';

    public const string LEAF_CHARSET = 'a leaf DSN must be printable ASCII with no space, control byte, parenthesis or fragment';

    public const string LEAF_SHAPE = 'a leaf DSN must read exactly scheme://authority[?query], with no path';

    public const string BAD_PERCENT_ESCAPE = 'a percent escape is malformed';

    public const string EMPTY_CREDENTIAL_HALF = 'the username or password half of the credential pair is empty';

    public const string BAD_QUERY = 'a query is a flat, unique, non-empty key=value list separated by "&"; brackets, dots, semicolons and repeated or case-colliding keys are refused';

    public const string UNKNOWN_OPTION = 'a query option is not on this transport family\'s allowlist';

    public const string BAD_OPTION_VALUE = 'a query option value is outside the shape or range the option accepts';

    public const string MISSING_PREREQUISITE = 'a query option is present while the option it is paired with is absent';

    public const string UNSUPPORTED_SCHEME = 'this scheme is not in the supported transport registry; the mailer documentation lists every scheme it will build';

    public const string MISSING_BRIDGE = 'the scheme is supported, but the Symfony bridge package that carries its factory is not installed';

    public const string WRONG_FACTORY = 'the class this scheme\'s official factory should occupy is something else, or resolves to something else';

    public const string WRONG_AUTHORITY = 'the host or port is not the shape this scheme\'s factory reads';

    public const string WRONG_CREDENTIALS = 'the credential halves are not what this scheme\'s factory reads';

    public const string CREDENTIAL_BYTES = 'a credential decodes to a byte outside printable ASCII';

    public const string CREDENTIAL_DROPPED = 'a credential half reading exactly "0" is discarded by the built-in SMTP factory rather than configured';

    public const string ASSEMBLY_FAILED = 'the transport could not be assembled from this configuration';

    public const string UNREADABLE = 'the configured value could not be read';

    public const string OPPORTUNISTIC_TLS = 'smtp:// requires required STARTTLS (require_tls=true), not opportunistic TLS';

    public const string PEER_VERIFICATION_BYPASS = 'certificate verification cannot be turned off';

    public const string UNPROVABLE_FAMILY = 'the factory returned a transport whose class is not exactly the one this scheme resolves to';

    public const string LOCAL_ONLY = 'this transport runs a local process and is available only when APP_ENV=development';

    public const string LOOPBACK_ONLY = 'the local-insecure profile covers only a DSN that is one direct smtp:// or smtps:// leaf whose host is loopback (localhost, 127.0.0.0/8, ::1)';

    public const string INSECURE_LOCAL_IN_PRODUCTION = 'the local-insecure profile is selected while APP_ENV is not development';

    public const string SENDMAIL_COMMAND = 'a sendmail command cannot be chosen from a DSN; only Symfony\'s own default command runs';

    public const string CONSTRUCTION_FAILED = 'the installed Symfony transport factory refused the leaf it was handed';

    public static function rejected(string $key, string $reason): self
    {
        return new self("{$key} was rejected: {$reason}.");
    }

    /**
     * The one message that names something beyond the config key: the
     * Composer package to install. It comes from
     * {@see \Kinetis\Mailer\Registry\TransportRegistry}'s own table,
     * not from the configured value, so it carries nothing a DSN wrote.
     */
    public static function missingBridge(string $key, string $package): self
    {
        return new self("{$key} was rejected: " . self::MISSING_BRIDGE . ". Run \"composer require {$package}\".");
    }

    public static function notSet(string $key): self
    {
        return new self("{$key} was rejected: " . self::NOT_SET . '.');
    }

    /**
     * The connection name is the one thing that cannot be quoted back,
     * since it is what failed the grammar that makes a name safe to
     * quote.
     */
    public static function invalidConnectionName(): self
    {
        return new self(
            'A mailer connection name must be 1 to 32 characters of lowercase letters and digits, '
            . 'in "_"-separated segments (default, transactional, ops_alerts).',
        );
    }
}
