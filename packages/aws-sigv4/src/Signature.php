<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use AsyncAws\Core\Credentials\Credentials;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * AWS Signature Version 4 for a request that is already in wire form:
 * canonical request, string to sign, derived signing key, and the
 * `Authorization` header the result goes out with.
 *
 * The request handed to sign() is the one that will be sent — its URI
 * built by {@see TrustedOrigin} from a path {@see WireTarget}
 * normalized, its body already buffered. Nothing here rewrites the
 * target, so the canonical request describes the bytes on the wire.
 *
 * ## Owned headers
 *
 * `Host`, `X-Amz-Date`, `Authorization` and `X-Amz-Security-Token`
 * belong to this class and are written over whatever the caller set.
 * `Host` comes from the URI's own authority, so it names the trusted
 * origin rather than a caller's claim about it, and the security-token
 * header is removed when the credentials carry no token.
 *
 * `X-Amz-Content-Sha256` is owned only where the caller set one: a
 * request carrying it — under any spelling, however many times — goes
 * out with a single value, the SHA-256 of the buffered body, so the
 * header a service reads as the payload hash names the same bytes the
 * canonical request does. A request without one stays without one.
 *
 * Every other header reaches the wire as the caller wrote it.
 *
 * ## Canonical form
 *
 * - URI: the wire path's own bytes percent-encoded segment by segment,
 *   the separators left as `/`. Everything outside the unreserved set
 *   is `%XX` here, the `%` of an escape already on the wire included,
 *   so wire `/a%2Fb` is canonically `/a%252Fb` and `/a/b` is `/a/b`.
 *   That second encoding layer is what the service applies to the
 *   request target it receives; the request line itself is unchanged.
 * - Query: each `&`-separated pair split at its first `=`, name and
 *   value decoded and re-encoded, pairs sorted bytewise by encoded name
 *   then encoded value, duplicates kept. A literal `+` is a plus and
 *   encodes as `%2B`; a space encodes as `%20`.
 * - Headers: names lowercased and sorted bytewise, values trimmed with
 *   internal whitespace collapsed to one space, repeated values joined
 *   with `,` in the order the request holds them.
 *
 * Only headers a transport owns are left out of the signature — see
 * TRANSPORT_HEADERS. `Content-Type` is signed: it decides how a service
 * reads the body.
 *
 * @internal Used by SigV4SigningClient.
 */
final class Signature
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    private const string TERMINATOR = 'aws4_request';

    private const string PAYLOAD_HASH_HEADER = 'X-Amz-Content-Sha256';

    /**
     * Headers an HTTP client sets, rewrites, or drops on the way out.
     * Signing one binds the signature to a value this package does not
     * control, and the request then fails verification at the service
     * for a reason no caller can see.
     *
     * @var array<string, true>
     */
    private const array TRANSPORT_HEADERS = [
        'authorization' => true,
        'content-length' => true,
        'expect' => true,
        'user-agent' => true,
        'accept-encoding' => true,
        'connection' => true,
        'transfer-encoding' => true,
        'te' => true,
        'proxy-authorization' => true,
    ];

    public function __construct(
        private readonly string $region,
        private readonly string $service,
    ) {}

    /**
     * Returns $request carrying the owned headers and its signature.
     *
     * $payload is the request body's exact bytes, already read by the
     * caller; the payload hash always comes from them.
     */
    public function sign(
        #[SensitiveParameter] RequestInterface $request,
        #[SensitiveParameter] Credentials $credentials,
        #[SensitiveParameter] string $payload,
        DateTimeImmutable $now,
    ): RequestInterface {
        // A SigV4 timestamp and credential scope are UTC, whatever zone
        // the clock carries.
        $instant = $now->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $instant->format('Ymd\THis\Z');
        $date = $instant->format('Ymd');
        $scope = $date . '/' . $this->region . '/' . $this->service . '/' . self::TERMINATOR;

        $signable = $request
            ->withHeader('Host', $request->getUri()->getAuthority())
            ->withHeader('X-Amz-Date', $timestamp)
            ->withoutHeader('Authorization');

        $sessionToken = $credentials->getSessionToken();
        $signable = $sessionToken === null
            ? $signable->withoutHeader('X-Amz-Security-Token')
            : $signable->withHeader('X-Amz-Security-Token', $sessionToken);

        $payloadHash = hash('sha256', $payload);

        // A service that reads X-Amz-Content-Sha256 as the payload hash
        // must read the hash of the bytes being sent. PSR-7 header
        // access is case-insensitive, so this replaces every spelling
        // and every repeat with the one value; a request that carries
        // no such header keeps none.
        if ($signable->hasHeader(self::PAYLOAD_HASH_HEADER)) {
            $signable = $signable->withHeader(self::PAYLOAD_HASH_HEADER, $payloadHash);
        }

        $headers = self::canonicalHeaders($signable);
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $signable->getMethod(),
            self::canonicalUri($signable->getUri()->getPath()),
            self::canonicalQuery($signable->getUri()->getQuery()),
            self::headerBlock($headers),
            $signedHeaders,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($credentials, $date));

        return $signable->withHeader('Authorization', \sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $credentials->getAccessKeyId(),
            $scope,
            $signedHeaders,
            $signature,
        ));
    }

    /**
     * The path canonicalizes as the bytes that go out: each segment
     * percent-encoded, the separators left alone. Nothing is decoded
     * first, so a `%` already on the wire encodes to `%25` and an
     * encoded slash reaches the canonical request as `%252F` — the text
     * a service derives from the target it receives, and what keeps
     * `/a%2Fb` from signing as `/a/b`.
     */
    private static function canonicalUri(#[SensitiveParameter] string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * Splitting on the wire query's own `&` and first `=` keeps a
     * repeated name as the several pairs it is, and keeps a `=` or `&`
     * inside a value — which arrives escaped — out of the split. A pair
     * with no `=` canonicalizes with an empty value.
     *
     * @param string $query the wire query string, with no leading `?`
     */
    private static function canonicalQuery(#[SensitiveParameter] string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $pairs[] = [self::reencode($parts[0]), self::reencode($parts[1] ?? '')];
        }

        usort(
            $pairs,
            static fn (array $left, array $right): int
                => strcmp($left[0], $right[0]) ?: strcmp($left[1], $right[1]),
        );

        return implode('&', array_map(
            static fn (array $pair): string => $pair[0] . '=' . $pair[1],
            $pairs,
        ));
    }

    private static function reencode(#[SensitiveParameter] string $component): string
    {
        return rawurlencode(rawurldecode($component));
    }

    /**
     * @return array<string, string> lowercased name to canonical value,
     *     in the bytewise name order the signature uses
     */
    private static function canonicalHeaders(#[SensitiveParameter] RequestInterface $request): array
    {
        $values = [];

        foreach ($request->getHeaders() as $name => $headerValues) {
            $lowercased = strtolower((string) $name);

            if (isset(self::TRANSPORT_HEADERS[$lowercased])) {
                continue;
            }

            foreach ($headerValues as $value) {
                $trimmed = trim($value);
                $values[$lowercased][] = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
            }
        }

        ksort($values, \SORT_STRING);

        return array_map(static fn (array $list): string => implode(',', $list), $values);
    }

    /**
     * Each header on its own line, the block itself ending in one, so
     * the canonical request's join leaves the blank line that separates
     * the headers from the signed-header list.
     *
     * @param array<string, string> $headers
     */
    private static function headerBlock(#[SensitiveParameter] array $headers): string
    {
        $block = '';

        foreach ($headers as $name => $value) {
            $block .= $name . ':' . $value . "\n";
        }

        return $block;
    }

    private function signingKey(#[SensitiveParameter] Credentials $credentials, string $date): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $credentials->getSecretKey(), true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', self::TERMINATOR, $key, true);
    }
}
