<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use Kinetis\AwsSigV4\Exception\SigningException;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;

/**
 * The one `http(s)://host[:port][/base-path]` origin a
 * `SigV4SigningClient` signs for, parsed by this package rather than by
 * `parse_url()`.
 *
 * `parse_url()` accepts inputs no HTTP origin may take — a host holding
 * a percent-encoded delimiter, a port that is not a number, a
 * backslash where a slash is expected — and reports nothing about the
 * components it discards, so a value it accepts is not an origin a
 * signature may be trusted against. The grammar here is the accepted
 * one, and everything outside it is rejected:
 *
 * - scheme: `http` or `https`, case-insensitive, always written as
 *   `scheme://`.
 * - authority: a host, optionally followed by `:` and a decimal port in
 *   1–65535. No userinfo, no percent sign.
 * - host: a registered name of dot-separated LDH labels, a dotted-quad
 *   IPv4 address, or a bracketed IPv6 address.
 * - path: empty, or `/`-prefixed and built from unreserved,
 *   sub-delimiter, `:`, `@`, `/` and well-formed `%XX` characters, with
 *   no `.` or `..` segment.
 * - no query string and no fragment.
 * - no whitespace, control character, or backslash anywhere in the
 *   value.
 *
 * Comparison is on scheme, host, and effective port: the scheme and a
 * registered name are lowercased, an IPv6 address is compared in its
 * packed form so `[0:0:0:0:0:0:0:1]` and `[::1]` are one origin, and an
 * absent port resolves to 80 for `http` and 443 for `https`.
 *
 * The base path is a second, separate constraint, and it binds every
 * request: a relative one is joined onto it, an absolute one must
 * already lie under it, and both are checked once the target is in the
 * form it will be sent in. Checking a path that still carries `..` would
 * say nothing about the path {@see WireTarget} produces from it, and the
 * second is what goes out. The base path is stored in that same form, so
 * the two sides of the comparison are one representation.
 *
 * @internal Constructed only by SigV4SigningClient.
 */
final class TrustedOrigin
{
    /**
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private const string SCHEME_PATTERN = '~^([A-Za-z][A-Za-z0-9+.\-]*)://(.*)$~s';

    private const string REGISTERED_NAME_PATTERN
        = '/^[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*\z/';

    private const string PATH_PATTERN = '{^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@/]|%[0-9A-Fa-f]{2})*\z}';

    private function __construct(
        public private(set) string $scheme,
        public private(set) string $host,
        public private(set) int $port,
        public private(set) string $basePath,
        private readonly int $defaultPort,
    ) {}

    public static function parse(#[SensitiveParameter] string $origin): self
    {
        if (preg_match('/[\x00-\x20\x7F]/', $origin) === 1 || str_contains($origin, '\\')) {
            throw SigningException::originHasAmbiguousCharacters();
        }

        if (preg_match(self::SCHEME_PATTERN, $origin, $matches) !== 1) {
            throw SigningException::originIsNotAbsolute();
        }

        $scheme = strtolower($matches[1]);
        $defaultPort = self::DEFAULT_PORTS[$scheme] ?? throw SigningException::originHasUnsupportedScheme();
        $remainder = $matches[2];

        if (strpbrk($remainder, '?#') !== false) {
            throw SigningException::originHasForbiddenComponents();
        }

        $pathStart = strpos($remainder, '/');
        $authority = $pathStart === false ? $remainder : substr($remainder, 0, $pathStart);
        $path = $pathStart === false ? '' : substr($remainder, $pathStart);

        if (str_contains($authority, '@')) {
            throw SigningException::originHasForbiddenComponents();
        }

        if (str_contains($authority, '%')) {
            throw SigningException::originHasEncodedAuthority();
        }

        [$host, $port] = self::splitAuthority($authority, $defaultPort);

        return new self($scheme, $host, $port, self::normalizeBasePath($path), $defaultPort);
    }

    /**
     * True when $uri names this exact origin: same scheme, same host,
     * same effective port. An `http` target against an `https` origin
     * differs in scheme, so a downgrade is a mismatch like any other.
     */
    public function matches(UriInterface $uri): bool
    {
        if (strtolower($uri->getScheme()) !== $this->scheme) {
            return false;
        }

        if (self::comparableHost($uri->getHost()) !== $this->host) {
            return false;
        }

        return ($uri->getPort() ?? $this->defaultPort) === $this->port;
    }

    /**
     * Joins a relative request's path onto the base path with exactly
     * one slash regardless of which side (if either) already supplies
     * one: PSR-7 permits a relative-reference path with no leading slash
     * (`users`), and concatenating that onto a base path directly
     * produces `/produsers` rather than `/prod/users`. An empty request
     * path keeps the base path as it is.
     *
     * The result is not yet normalized and not yet checked: a request
     * path of `../admin` joins to `/prod/../admin` here and is resolved
     * and rejected by the caller.
     */
    public function join(#[SensitiveParameter] string $requestPath): string
    {
        return $requestPath === ''
            ? $this->basePath
            : $this->basePath . '/' . ltrim($requestPath, '/');
    }

    /**
     * True when $path is the base path or lies under it, compared
     * segment-wise so `/production` does not pass for the base path
     * `/prod`. An origin with no base path covers every path.
     *
     * $path must already be normalized — see {@see WireTarget}. A path
     * still carrying `..`, or an escape spelling one, is not the path
     * that will be sent, and this answers about the one it is given.
     */
    public function coversPath(#[SensitiveParameter] string $path): bool
    {
        if ($this->basePath === '') {
            return true;
        }

        return $path === $this->basePath || str_starts_with($path, $this->basePath . '/');
    }

    /**
     * The absolute URI to sign and send: this origin's own canonical
     * scheme, host and port, with $path and $query as given.
     *
     * The URI is built here rather than derived from the caller's, so
     * the string the signer reads and the string the transport sends are
     * both this package's own, whatever the caller's PSR-7
     * implementation renders. The port is written only when it is not
     * the scheme's default, so the `Host` header it produces carries one
     * only where it is meaningful.
     */
    public function targetFor(#[SensitiveParameter] string $path, #[SensitiveParameter] string $query): UriInterface
    {
        $authority = $this->host . ($this->port === $this->defaultPort ? '' : ':' . $this->port);

        return new Uri($this->scheme . '://' . $authority . $path . ($query === '' ? '' : '?' . $query));
    }

    /**
     * @return array{string, int}
     */
    private static function splitAuthority(#[SensitiveParameter] string $authority, int $defaultPort): array
    {
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');

            if ($close === false) {
                throw SigningException::originHasInvalidHost();
            }

            $host = self::normalizeIpV6(substr($authority, 1, $close - 1));
            $portPart = substr($authority, $close + 1);
        } else {
            $colon = strpos($authority, ':');
            $host = self::normalizeRegisteredName($colon === false ? $authority : substr($authority, 0, $colon));
            $portPart = $colon === false ? '' : substr($authority, $colon);
        }

        if ($portPart === '') {
            return [$host, $defaultPort];
        }

        if (!str_starts_with($portPart, ':')) {
            throw SigningException::originHasInvalidHost();
        }

        $port = substr($portPart, 1);

        if (preg_match('/^[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw SigningException::originHasInvalidPort();
        }

        return [$host, (int) $port];
    }

    private static function normalizeRegisteredName(#[SensitiveParameter] string $host): string
    {
        if (preg_match('/^[0-9.]+\z/', $host) === 1) {
            if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
                throw SigningException::originHasInvalidHost();
            }

            return $host;
        }

        if (strlen($host) > 253 || preg_match(self::REGISTERED_NAME_PATTERN, $host) !== 1) {
            throw SigningException::originHasInvalidHost();
        }

        return strtolower($host);
    }

    /**
     * Returns the bracketed, packed-then-printed form, which is the one
     * comparison uses: `inet_ntop(inet_pton(...))` collapses every
     * spelling of one address onto a single string.
     */
    private static function normalizeIpV6(#[SensitiveParameter] string $address): string
    {
        if (filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            throw SigningException::originHasInvalidHost();
        }

        $packed = inet_pton($address);
        $printed = $packed === false ? false : inet_ntop($packed);

        if ($printed === false) {
            throw SigningException::originHasInvalidHost();
        }

        return '[' . strtolower($printed) . ']';
    }

    /**
     * A base path is rejected rather than repaired when it carries a `.`
     * or `..` segment — an origin is configuration, and configuration
     * that does not say what it means is a mistake to report, not one to
     * resolve. What is left is put into the same wire form every request
     * path is, so the two compare as written; a trailing slash is
     * dropped so joining supplies exactly one.
     */
    private static function normalizeBasePath(#[SensitiveParameter] string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw SigningException::originHasInvalidPath();
        }

        $normalized = rtrim(WireTarget::normalizeEncoding($path), '/');

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw SigningException::originHasInvalidPath();
            }
        }

        return $normalized;
    }

    private static function comparableHost(string $host): string
    {
        if (!str_starts_with($host, '[') || !str_ends_with($host, ']')) {
            return strtolower($host);
        }

        $packed = inet_pton(substr($host, 1, -1));
        $printed = $packed === false ? false : inet_ntop($packed);

        return $printed === false ? strtolower($host) : '[' . strtolower($printed) . ']';
    }
}
