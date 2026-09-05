<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

/**
 * RFC 4648 §5 base64url without padding — the encoding RFC 7515 §2 and
 * RFC 7518 §6.3.1 use for every JWS segment and every RSA integer in a
 * JWK.
 *
 * decode() accepts one spelling of any given byte string and rejects
 * every other: a decoder ignores the unused low bits of a final
 * character, so `AQ` and `AR` both decode to `\x01`, as do `=` padding
 * and the standard `+/` alphabet. A JWS signs its own base64url header
 * and payload, so those spellings are distinct documents carrying the
 * same bytes, and one token or key set could arrive under inputs that
 * are not byte-for-byte equal. Every decode therefore re-encodes and
 * requires the result to match its input exactly.
 *
 * @internal Boundary helper for JwkSet, ParsedJwkSet and JoseHeader.
 */
final class Base64Url
{
    private const string ALPHABET_PATTERN = '/\A[A-Za-z0-9_-]+\z/';

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Returns the decoded bytes, or null when $value is empty, outside
     * the base64url alphabet, or any spelling other than the one
     * encode() produces for the bytes it decodes to.
     */
    public static function decode(#[\SensitiveParameter] string $value): ?string
    {
        if (preg_match(self::ALPHABET_PATTERN, $value) !== 1) {
            return null;
        }

        $remainder = strlen($value) % 4;
        $padded = strtr($value, '-_', '+/') . str_repeat('=', $remainder === 0 ? 0 : 4 - $remainder);
        $decoded = base64_decode($padded, true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        return self::encode($decoded) === $value ? $decoded : null;
    }
}
