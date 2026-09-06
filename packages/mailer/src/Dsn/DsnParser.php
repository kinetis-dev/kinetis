<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Dsn;

use Kinetis\Mailer\Exception\MailerConfigurationException;

/**
 * The whole-input grammar for a Kinetis mailer DSN, and the only thing
 * that reads one.
 *
 * ```text
 * dsn        = node
 * node       = leaf / group
 * group      = ("failover" / "roundrobin") "(" node *(SP node) ")" [ "?retry_period=" 1*5DIGIT ]
 * leaf       = scheme "://" authority [ "?" query ]
 * authority  = [ user [ ":" password ] "@" ] host [ ":" port ]
 * query      = pair *("&" pair)
 * pair       = key "=" value
 * ```
 *
 * Exactly one ASCII space separates two members. A tab, a newline, two
 * spaces, a leading or trailing space, an empty group, an unknown
 * keyword, an unbalanced parenthesis and anything after the closing one
 * are all rejected, and the parse must consume the input to its last
 * byte.
 *
 * Symfony's own parser is not used, and the complete string is never
 * handed back to it. `Transport::parseDsn()` is private, recurses while
 * it parses, and constructs each transport as it goes, so a member deep
 * inside a group is already built by the time an outer member turns out
 * to be insecure; its balanced-parenthesis match is a recursive PCRE with
 * no bound of its own. `Dsn::fromString()`, the public entry point, runs
 * `parse_url()` and `parse_str()` — and `parse_str()` has already
 * collapsed a repeated key and rewritten `a.b` to `a_b` before any policy
 * could see either. Everything structural is decided here first, from the
 * raw bytes.
 *
 * Five caps bound the work before it is done, each checked before the
 * recursion or allocation it guards:
 *
 * | Cap | Limit |
 * |---|---|
 * | complete DSN | 8192 bytes |
 * | group nesting | 8 levels |
 * | leaf transports | 16 |
 * | query pairs per leaf | 32 |
 * | decoded credential or option value | 1024 bytes |
 *
 * @internal to kinetis/mailer
 */
final class DsnParser
{
    public const int MAX_BYTES = 8192;

    public const int MAX_DEPTH = 8;

    public const int MAX_LEAVES = 16;

    public const int MAX_QUERY_PAIRS = 32;

    public const int MAX_VALUE_BYTES = 1024;

    /** Symfony's own `RoundRobinTransport` default, in seconds. */
    public const int DEFAULT_RETRY_PERIOD = 60;

    public const int MAX_RETRY_PERIOD = 86400;

    /**
     * Printable ASCII. Space and `)` have already ended the leaf by the
     * time this runs; `(` and `#` are refused here, so a group cannot
     * open inside a leaf and no fragment can ride along.
     */
    private const string LEAF_CHARS = '/^[\x21-\x7E]+$/D';

    private const string LEAF_SHAPE = '{^(?<scheme>[a-z][a-z0-9]{0,31}(?:\+[a-z0-9]{1,15}){0,2})://(?<authority>[^?]*)(?:\?(?<query>.*))?$}D';

    /** RFC 3986 userinfo, minus `:` (the half separator) and the structural bytes. */
    private const string USERINFO_CHARS = '/^[A-Za-z0-9\-._~!$&\'*+,;=%]+$/D';

    /** One DNS label: no empty label, no underscore, no leading or trailing hyphen, 63 bytes at most. */
    private const string DNS_LABEL = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D';

    private const int MAX_HOST_BYTES = 253;

    /**
     * What a decoded credential half may hold: printable ASCII and
     * nothing else. The raw form is already printable, but a percent
     * escape decodes after that check — `%0D%0A` is two ordinary
     * characters until it becomes a line break inside an SMTP AUTH
     * exchange or an API header.
     */
    private const string DECODED_CREDENTIAL = '/^[\x20-\x7E]+$/D';

    private const string QUERY_KEY_CHARS = '/^[A-Za-z0-9_]{1,64}$/D';

    private const string QUERY_VALUE_CHARS = '/^[A-Za-z0-9\-._~!$\'*+,;=:@\/?%]+$/D';

    /** Every `%` starts a complete two-digit hex escape. */
    private const string PERCENT_ESCAPES = '/%(?![0-9A-Fa-f]{2})/';

    private int $offset = 0;

    private int $leaves = 0;

    private function __construct(
        #[\SensitiveParameter]
        private readonly string $dsn,
        private readonly string $key,
    ) {}

    /**
     * @param string $key the config key the DSN came from, the only value
     *     any resulting message quotes
     *
     * @throws MailerConfigurationException
     */
    public static function parse(#[\SensitiveParameter] string $dsn, string $key): DsnNode
    {
        if ($dsn === '') {
            throw MailerConfigurationException::rejected($key, MailerConfigurationException::EMPTY_VALUE);
        }

        if (strlen($dsn) > self::MAX_BYTES) {
            throw MailerConfigurationException::rejected($key, MailerConfigurationException::TOO_LONG);
        }

        $parser = new self($dsn, $key);
        $node = $parser->node(0);

        if ($parser->offset !== strlen($dsn)) {
            throw MailerConfigurationException::rejected($key, MailerConfigurationException::TRAILING_GARBAGE);
        }

        return $node;
    }

    private function node(int $depth): DsnNode
    {
        foreach (CompositeKind::cases() as $kind) {
            $open = $kind->value . '(';

            if (substr($this->dsn, $this->offset, strlen($open)) === $open) {
                return $this->composite($kind, strlen($open), $depth);
            }
        }

        return $this->leaf();
    }

    private function composite(CompositeKind $kind, int $openLength, int $depth): CompositeDsn
    {
        if ($depth + 1 > self::MAX_DEPTH) {
            throw $this->reject(MailerConfigurationException::TOO_DEEP);
        }

        $this->offset += $openLength;
        $members = [];

        while (true) {
            $members[] = $this->node($depth + 1);
            $next = $this->dsn[$this->offset] ?? null;

            if ($next === ')') {
                ++$this->offset;

                break;
            }

            if ($next !== ' ') {
                throw $this->reject(MailerConfigurationException::BAD_COMPOSITE);
            }

            ++$this->offset;
        }

        return new CompositeDsn($kind, $members, $this->retryPeriod());
    }

    /**
     * @return int<1, 86400>
     */
    private function retryPeriod(): int
    {
        if (($this->dsn[$this->offset] ?? null) !== '?') {
            return self::DEFAULT_RETRY_PERIOD;
        }

        ++$this->offset;
        $clause = $this->take();

        if (preg_match('/^retry_period=([1-9][0-9]{0,4})$/D', $clause, $matches) !== 1) {
            throw $this->reject(MailerConfigurationException::BAD_RETRY_PERIOD);
        }

        $period = (int) $matches[1];

        if ($period > self::MAX_RETRY_PERIOD) {
            throw $this->reject(MailerConfigurationException::BAD_RETRY_PERIOD);
        }

        return $period;
    }

    private function leaf(): LeafDsn
    {
        $raw = $this->take();

        if ($raw === '') {
            throw $this->reject(MailerConfigurationException::EMPTY_MEMBER);
        }

        if (++$this->leaves > self::MAX_LEAVES) {
            throw $this->reject(MailerConfigurationException::TOO_MANY_LEAVES);
        }

        return $this->leafFrom($raw);
    }

    /**
     * Everything from the current offset up to the next member separator,
     * closing parenthesis or end of input — consuming it.
     */
    private function take(): string
    {
        $length = strcspn($this->dsn, ' )', $this->offset);
        $taken = substr($this->dsn, $this->offset, $length);
        $this->offset += $length;

        return $taken;
    }

    private function leafFrom(#[\SensitiveParameter] string $raw): LeafDsn
    {
        if (preg_match(self::LEAF_CHARS, $raw) !== 1 || strpbrk($raw, '(#') !== false) {
            throw $this->reject(MailerConfigurationException::LEAF_CHARSET);
        }

        if (preg_match(self::LEAF_SHAPE, $raw, $matches) !== 1) {
            throw $this->reject(MailerConfigurationException::LEAF_SHAPE);
        }

        $authority = $matches['authority'];

        if ($authority === '' || str_contains($authority, '/')) {
            throw $this->reject(MailerConfigurationException::LEAF_SHAPE);
        }

        [$user, $password, $host, $port] = $this->authority($authority);
        $query = $matches['query'] ?? null;

        return new LeafDsn(
            $matches['scheme'],
            $host,
            $user,
            $password,
            $port,
            $query === null ? [] : $this->query($query),
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?int}
     */
    private function authority(#[\SensitiveParameter] string $authority): array
    {
        $at = strrpos($authority, '@');
        $user = null;
        $password = null;

        if ($at !== false) {
            $credential = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
            $colon = strpos($credential, ':');
            $rawUser = $colon === false ? $credential : substr($credential, 0, $colon);
            $rawPassword = $colon === false ? null : substr($credential, $colon + 1);

            if ($rawUser === '' || $rawPassword === '') {
                throw $this->reject(MailerConfigurationException::EMPTY_CREDENTIAL_HALF);
            }

            $user = $this->credential($rawUser);
            $password = $rawPassword === null ? null : $this->credential($rawPassword);
        }

        $port = null;
        $colon = strrpos($authority, ':');

        if ($colon !== false && !str_ends_with($authority, ']')) {
            $rawPort = substr($authority, $colon + 1);
            $authority = substr($authority, 0, $colon);

            if (preg_match('/^[1-9][0-9]{0,4}$/D', $rawPort) !== 1 || (int) $rawPort > 65535) {
                throw $this->reject(MailerConfigurationException::LEAF_SHAPE);
            }

            $port = (int) $rawPort;
        }

        if (!self::isHost($authority)) {
            throw $this->reject(MailerConfigurationException::LEAF_SHAPE);
        }

        return [$user, $password, $authority, $port];
    }

    /**
     * A bracketed IPv6 literal, an IPv4 address, or a DNS name of real
     * labels. The literal is checked as an address rather than as a run
     * of hex and colons, and a name may not carry an empty label, an
     * underscore, a tilde or a label past 63 bytes — each of which a
     * resolver, a certificate matcher and a mail server can read
     * differently.
     */
    private static function isHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if ($host === '' || strlen($host) > self::MAX_HOST_BYTES) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match(self::DNS_LABEL, $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function query(#[\SensitiveParameter] string $query): array
    {
        if ($query === '' || str_contains($query, ';')) {
            throw $this->reject(MailerConfigurationException::BAD_QUERY);
        }

        $pairs = explode('&', $query);

        if (count($pairs) > self::MAX_QUERY_PAIRS) {
            throw $this->reject(MailerConfigurationException::TOO_MANY_QUERY_PAIRS);
        }

        $options = [];
        $seen = [];

        foreach ($pairs as $pair) {
            $equals = strpos($pair, '=');

            if ($equals === false || $equals === 0 || $equals === strlen($pair) - 1) {
                throw $this->reject(MailerConfigurationException::BAD_QUERY);
            }

            $key = substr($pair, 0, $equals);

            if (preg_match(self::QUERY_KEY_CHARS, $key) !== 1) {
                throw $this->reject(MailerConfigurationException::BAD_QUERY);
            }

            $folded = strtolower($key);

            if (isset($seen[$folded])) {
                throw $this->reject(MailerConfigurationException::BAD_QUERY);
            }

            $seen[$folded] = true;
            $options[$key] = $this->decode(substr($pair, $equals + 1), self::QUERY_VALUE_CHARS);
        }

        return $options;
    }

    /**
     * A credential half, decoded and then checked again: percent
     * decoding happens after the raw printable-ASCII check, so an
     * encoded control byte would otherwise arrive at an SMTP AUTH
     * exchange or a provider header as the byte it decodes to. An
     * escaped delimiter such as `%40` stays an ordinary `@`.
     */
    private function credential(#[\SensitiveParameter] string $raw): string
    {
        $decoded = $this->decode($raw, self::USERINFO_CHARS);

        if (preg_match(self::DECODED_CREDENTIAL, $decoded) !== 1) {
            throw $this->reject(MailerConfigurationException::CREDENTIAL_BYTES);
        }

        return $decoded;
    }

    /**
     * One decode, after the raw form has been proven to hold only
     * characters the grammar allows and only complete percent escapes.
     * The 1024-byte cap is applied to the raw form, which bounds the
     * decoded one too: percent-decoding replaces three bytes with one and
     * never grows a value.
     */
    private function decode(#[\SensitiveParameter] string $raw, string $charset): string
    {
        if (strlen($raw) > self::MAX_VALUE_BYTES) {
            throw $this->reject(MailerConfigurationException::VALUE_TOO_LONG);
        }

        if (preg_match($charset, $raw) !== 1) {
            throw $this->reject(MailerConfigurationException::LEAF_CHARSET);
        }

        if (preg_match(self::PERCENT_ESCAPES, $raw) === 1) {
            throw $this->reject(MailerConfigurationException::BAD_PERCENT_ESCAPE);
        }

        return rawurldecode($raw);
    }

    private function reject(string $reason): MailerConfigurationException
    {
        return MailerConfigurationException::rejected($this->key, $reason);
    }
}
