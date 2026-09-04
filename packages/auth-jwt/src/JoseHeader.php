<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

/**
 * The `alg`/`kid` pair out of a compact JWT's own JOSE header,
 * validated before any of the token reaches Firebase\JWT\JWT::decode().
 *
 * A JOSE header arrives unsigned and shaped however its sender chose.
 * JWT::decode() types `alg` and `kid` only where it uses them — it
 * indexes its algorithm table with `$header->alg` and passes
 * `$header->kid` into a `?string` parameter — so either one given as a
 * JSON array or object raises a TypeError from inside the library
 * rather than a decode failure a caller can classify as an
 * authentication failure.
 *
 * `crit` (RFC 7515 §4.1.11) and `b64` (RFC 7797) are refused outright:
 * the first names header members a verifier must understand or reject
 * the token over, the second moves the signature onto an unencoded
 * payload. This package implements neither, and firebase/php-jwt reads
 * neither, so refusing them here is what keeps a token from verifying
 * under semantics nothing honors. Every other member is left unread.
 *
 * parse() answers null for every rejection rather than naming a reason:
 * its caller's whole vocabulary for an unusable token is one generic
 * 401 (see JwtAuthMiddleware).
 *
 * The limits are fixed rather than configurable, and each sits far
 * above what a real token needs, so a hostile sender cannot choose how
 * much this package decodes.
 */
final class JoseHeader
{
    /**
     * A token this long already exceeds what a common HTTP front end
     * will carry in a single header line.
     */
    public const int MAXIMUM_TOKEN_LENGTH = 16384;

    public const int MAXIMUM_HEADER_SEGMENT_LENGTH = 4096;

    public const int MAXIMUM_HEADER_BYTES = 2048;

    private const int MAXIMUM_JSON_DEPTH = 8;

    private const array REFUSED_MEMBERS = ['crit', 'b64'];

    private function __construct(
        public private(set) string $alg,
        public private(set) ?string $kid,
    ) {}

    /**
     * Returns the validated header, or null when $token is not a
     * compact JWS this package can verify.
     *
     * $kidRequired states whether the verifying key is selected by
     * `kid` — true for every multi-key form, where a token with no
     * usable `kid` selects nothing. A present `kid` is held to
     * JwtKeyValidator's own kid rule either way, whether or not the
     * configured key would have read it.
     */
    public static function parse(#[\SensitiveParameter] string $token, bool $kidRequired): ?self
    {
        if ($token === '' || strlen($token) > self::MAXIMUM_TOKEN_LENGTH) {
            return null;
        }

        $segments = explode('.', $token);

        if (count($segments) !== 3 || strlen($segments[0]) > self::MAXIMUM_HEADER_SEGMENT_LENGTH) {
            return null;
        }

        // Every segment, not only the one read below — see Base64Url.
        $headerJson = Base64Url::decode($segments[0]);
        $payloadDecodes = Base64Url::decode($segments[1]) !== null;
        $signatureDecodes = Base64Url::decode($segments[2]) !== null;

        if ($headerJson === null || !$payloadDecodes || !$signatureDecodes) {
            return null;
        }

        $header = StrictJson::decodeObject($headerJson, self::MAXIMUM_HEADER_BYTES, self::MAXIMUM_JSON_DEPTH);

        if ($header === null) {
            return null;
        }

        foreach (self::REFUSED_MEMBERS as $member) {
            if (array_key_exists($member, $header)) {
                return null;
            }
        }

        $alg = $header['alg'] ?? null;

        if (!is_string($alg) || !in_array($alg, JwtKeyValidator::SUPPORTED_ALGORITHMS, true)) {
            return null;
        }

        if (!array_key_exists('kid', $header)) {
            return $kidRequired ? null : new self($alg, null);
        }

        $kid = $header['kid'];

        return JwtKeyValidator::isUsableKidValue($kid) ? new self($alg, $kid) : null;
    }
}
