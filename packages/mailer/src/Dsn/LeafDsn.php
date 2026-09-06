<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Dsn;

use Symfony\Component\Mailer\Transport\Dsn as SymfonyDsn;

/**
 * One validated leaf transport, already split into the exact components a
 * `Symfony\Component\Mailer\Transport\Dsn` is built from.
 *
 * Those components are why {@see toSymfonyDsn()} hands Symfony a
 * constructed object rather than a string: `Dsn::fromString()` runs
 * `parse_url()` and `parse_str()`, and `parse_str()` rewrites `.` and
 * space in a key to `_`, collapses a repeated key to its last value, and
 * turns `a[b]` into a nested array — each of which would change an option
 * after the allowlist had already approved a different one.
 * {@see DsnParser} decides the components; nothing re-derives them.
 *
 * `$user`, `$password` and `$options` are marked sensitive so a stack
 * trace capturing this constructor frame renders them as
 * `Object(SensitiveParameterValue)` rather than as the credential they
 * hold.
 *
 * @internal to kinetis/mailer
 */
final readonly class LeafDsn implements DsnNode
{
    /**
     * @param array<string, string> $options decoded once, keyed by the
     *     exact key as written
     */
    public function __construct(
        public string $scheme,
        public string $host,
        #[\SensitiveParameter]
        public ?string $user,
        #[\SensitiveParameter]
        public ?string $password,
        public ?int $port,
        #[\SensitiveParameter]
        public array $options,
    ) {}

    public function option(string $key): ?string
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Whether the host is a bracketed IPv6 literal or a bare IPv4
     * address rather than a name. Both are legitimate SMTP hosts, and
     * neither passes a hostname check.
     */
    public function isAddressLiteral(): bool
    {
        if (str_starts_with($this->host, '[') && str_ends_with($this->host, ']')) {
            return filter_var(substr($this->host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Whether the host names the local machine over loopback — the only
     * host the local-insecure profile covers. Compared as a literal: a
     * name that resolves to 127.0.0.1 today is a DNS answer, not a
     * property of the configuration, and the profile is scoped to what
     * the configuration itself says.
     */
    public function hasLoopbackHost(): bool
    {
        $host = strtolower(trim($this->host, '[]'));

        if ($host === 'localhost' || $host === '::1' || $host === '0:0:0:0:0:0:0:1') {
            return true;
        }

        return str_starts_with($host, '127.')
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function toSymfonyDsn(): SymfonyDsn
    {
        return new SymfonyDsn($this->scheme, $this->host, $this->user, $this->password, $this->port, $this->options);
    }
}
