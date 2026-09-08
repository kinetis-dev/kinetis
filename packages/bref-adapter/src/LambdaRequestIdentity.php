<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter;

use Kinetis\BrefAdapter\Exception\BrefAdapterException;

/**
 * Where the request was addressed, decided once, from the event.
 *
 * A payload-v2 event describes its own identity in five places that can
 * disagree: `requestContext.domainName`, the `host` header,
 * `x-forwarded-proto`, `x-forwarded-port`, and
 * `requestContext.http.protocol`. Reading each of them where it happens
 * to be needed produces a request whose URI, `Host` header and
 * request target are three different answers to the same question — and
 * an application generating an absolute URL, signing a canonical
 * request, or comparing an origin then behaves differently under Lambda
 * than under any other runtime for no reason it can see.
 *
 * So one field is authoritative for each part, every other field is
 * required to agree with it, and an event where they don't is refused
 * before anything is dispatched:
 *
 * - **Host**: `requestContext.domainName`. API Gateway sets it from the
 *   domain the client actually reached, and it is the one field a client
 *   cannot write. A `host` header is accepted only if it names that same
 *   domain (with or without a port).
 * - **Port**: `x-forwarded-port`, or the port in the `host` header, and
 *   they must match when both are present. Absent means the scheme's
 *   default, which is what every other runtime reports too.
 * - **Scheme**: `https`, always. An API Gateway HTTP API and a Lambda
 *   Function URL are TLS-only — there is no plaintext listener for a
 *   request to have arrived over — so the scheme is a property of the
 *   platform rather than of the event. `x-forwarded-proto` is checked
 *   against that fact instead of deciding it: absent or `https` is the
 *   event API Gateway builds, and any other value, `http` included,
 *   describes an invocation that cannot have happened and is refused
 *   with everything else that contradicts itself.
 * - **Protocol version**: `requestContext.http.protocol`, the version
 *   the client negotiated with API Gateway.
 * - **Request target**: `rawPath` and `rawQueryString`, byte for byte.
 *   Those two are the raw bytes API Gateway received; the event's own
 *   `queryStringParameters` is its lossy summary of the same query — it
 *   comma-joins a repeated parameter into one value, which PHP would
 *   then read as a single parameter whose value contains a comma — so it
 *   is not read anywhere. `parse_str()` over the raw query
 *   is what every other runtime does with the same bytes.
 *
 * Every string that ends up in the URI is required to be valid UTF-8
 * with no control characters. Invalid UTF-8 in a path would otherwise
 * travel as far as `json_encode()` on the response payload and fail
 * there, turning a bad request into a failed invocation; and a control
 * character in a target is request smuggling looking for somewhere to
 * land.
 */
final readonly class LambdaRequestIdentity
{
    /** The scheme every supported invocation source serves over. */
    private const string SCHEME = 'https';

    /** Its default port, and so the one an authority never spells out. */
    private const int DEFAULT_PORT = 443;

    private function __construct(
        public string $method,
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public string $rawQueryString,
        public string $protocolVersion,
    ) {}

    /**
     * @param array<string, mixed> $event
     * @param array<string, string> $headers the event's headers, names
     *     already lowercased, as {@see BrefLambdaAdapter} reads them
     */
    public static function fromEvent(array $event, array $headers): self
    {
        $http = $event['requestContext']['http'] ?? null;
        $http = is_array($http) ? $http : [];

        $method = self::requireToken($http['method'] ?? null, 'requestContext.http.method');
        $domain = strtolower(self::requireToken($event['requestContext']['domainName'] ?? null, 'requestContext.domainName'));

        if (preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
            throw BrefAdapterException::malformedInvocationEvent('requestContext.domainName is not a host name.');
        }

        self::assertScheme($headers);
        $port = self::port($headers, $domain);
        $path = self::requireTarget($event['rawPath'] ?? null, 'rawPath');

        if (!str_starts_with($path, '/') || strpbrk($path, '?#') !== false) {
            throw BrefAdapterException::malformedInvocationEvent('rawPath is not an absolute path, or carries a query or fragment of its own.');
        }

        $rawQueryString = $event['rawQueryString'] ?? '';

        if (!is_string($rawQueryString)) {
            throw BrefAdapterException::malformedInvocationEvent('rawQueryString must be a string when present.');
        }

        if ($rawQueryString !== '') {
            self::requireTarget($rawQueryString, 'rawQueryString');

            if (str_contains($rawQueryString, '#')) {
                throw BrefAdapterException::malformedInvocationEvent('rawQueryString carries a fragment.');
            }
        }

        // A port that is the scheme's default is not part of an
        // authority. Dropped here, once, so the URI, the `Host` header
        // and anything else built from this identity agree — PSR-7's own
        // URI does the same thing to it, and only one of the three doing
        // it is how they end up disagreeing.
        if ($port === self::DEFAULT_PORT) {
            $port = null;
        }

        return new self($method, self::SCHEME, $domain, $port, $path, $rawQueryString, self::protocolVersion($http));
    }

    /**
     * The authority as it belongs in a `Host` header — and, because the
     * port is the scheme's default or absent, exactly what PSR-7 will
     * report back from the URI.
     */
    public function authority(): string
    {
        return $this->port === null ? $this->host : "{$this->host}:{$this->port}";
    }

    public function uri(): string
    {
        return "{$this->scheme}://{$this->authority()}{$this->requestTarget()}";
    }

    public function requestTarget(): string
    {
        return $this->rawQueryString === '' ? $this->path : "{$this->path}?{$this->rawQueryString}";
    }

    /**
     * The scheme is {@see SCHEME} whatever the event says, so this only
     * has to establish that the event agrees. An HTTP API and a Function
     * URL both terminate TLS themselves and have no plaintext mode to
     * fall back to, which makes `http` here not a downgrade to weigh
     * against a trust policy but a claim about how the request reached
     * the gateway that the gateway cannot have made. Refused rather than
     * honored — an application whose absolute URLs, `Secure` cookies and
     * redirect targets turn plaintext on one invocation is the cost of
     * believing it — and refused rather than ignored, because an event
     * that describes an impossible invocation is one this adapter has
     * misread or something has rewritten.
     *
     * @param array<string, string> $headers
     */
    private static function assertScheme(array $headers): void
    {
        if (strtolower(trim($headers['x-forwarded-proto'] ?? self::SCHEME)) !== self::SCHEME) {
            throw BrefAdapterException::malformedInvocationEvent('the x-forwarded-proto header names a scheme other than https, which no supported invocation source serves over.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function port(array $headers, string $domain): ?int
    {
        $fromHeader = self::hostHeaderPort($headers, $domain);
        $forwarded = $headers['x-forwarded-port'] ?? null;

        if ($forwarded === null) {
            return $fromHeader;
        }

        if (preg_match('/^\d{1,5}$/', $forwarded) !== 1 || (int) $forwarded < 1 || (int) $forwarded > 65_535) {
            throw BrefAdapterException::malformedInvocationEvent('the x-forwarded-port header is not a port number.');
        }

        if ($fromHeader !== null && $fromHeader !== (int) $forwarded) {
            throw BrefAdapterException::malformedInvocationEvent('the host and x-forwarded-port headers name different ports.');
        }

        return (int) $forwarded;
    }

    /**
     * The `host` header is the client's, so it is checked against
     * `domainName` rather than trusted: a header naming a different
     * domain is either a misrouted event or an attempt to make the
     * application build URLs pointing somewhere else.
     *
     * @param array<string, string> $headers
     */
    private static function hostHeaderPort(array $headers, string $domain): ?int
    {
        $host = $headers['host'] ?? null;

        if ($host === null) {
            return null;
        }

        if (preg_match('/^([a-zA-Z0-9.-]+)(?::(\d{1,5}))?$/', trim($host), $matches) !== 1) {
            throw BrefAdapterException::malformedInvocationEvent('the host header is not a host name with an optional port.');
        }

        if (strtolower($matches[1]) !== $domain) {
            throw BrefAdapterException::malformedInvocationEvent('the host header and requestContext.domainName name different hosts.');
        }

        if (!isset($matches[2])) {
            return null;
        }

        $port = (int) $matches[2];

        if ($port < 1 || $port > 65_535) {
            throw BrefAdapterException::malformedInvocationEvent('the host header names a port outside the valid range.');
        }

        return $port;
    }

    /**
     * @param array<array-key, mixed> $http
     */
    private static function protocolVersion(array $http): string
    {
        $protocol = $http['protocol'] ?? 'HTTP/1.1';

        if (!is_string($protocol) || preg_match('#^HTTP/(\d(?:\.\d)?)$#', $protocol, $matches) !== 1) {
            throw BrefAdapterException::malformedInvocationEvent('requestContext.http.protocol is not an HTTP version.');
        }

        return $matches[1];
    }

    private static function requireToken(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $value) !== 1) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} is missing or is not a token.");
        }

        return $value;
    }

    /**
     * A path or query as it will appear in the request target: real
     * UTF-8, and nothing a header, a request line or a log entry could
     * be broken apart with.
     */
    private static function requireTarget(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw BrefAdapterException::malformedInvocationEvent("{$field} is missing or empty.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} is not valid UTF-8.");
        }

        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw BrefAdapterException::malformedInvocationEvent("{$field} contains a control character or a space.");
        }

        return $value;
    }
}
