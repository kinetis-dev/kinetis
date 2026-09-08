<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use UnexpectedValueException;

/**
 * Parses an RFC 7517 JWK Set from raw JSON into the verification keys
 * JwtVerificationKeys::jwks() selects among — the consuming half of what
 * JwkSet publishes.
 *
 * parse() returns a set whose every key is usable, or throws — never a
 * partial set with the failing keys dropped. RFC 7517 §5 allows a
 * document to carry members beyond the ones a reader understands and
 * requires those to be ignored, so an unrecognized member at the root or
 * inside a key is skipped; every member this package does read is
 * enforced, and published private or symmetric material is refused.
 *
 * Size limits are fixed rather than configurable, so how much this
 * package decodes stays its own decision rather than the sender's.
 */
final class JwkSetParser
{
    public const int MAXIMUM_JSON_BYTES = 65536;

    public const int MAXIMUM_KEYS = 32;

    /**
     * Prefixes every kid before it becomes an array key. A kid is an
     * opaque string; a PHP array key is not, since `"0"` used as one
     * becomes the integer 0. A fixed non-numeric literal is injective
     * and leaves no kid in the canonical decimal form PHP would coerce,
     * so `"0"`, `"00"` and `"zero"` stay three separately selectable
     * keys.
     */
    public const string LOOKUP_PREFIX = 'kid#';

    private const int MAXIMUM_JSON_DEPTH = 8;

    /**
     * Long enough for the base64url form of an 8192-bit modulus, four
     * times the minimum this package accepts.
     */
    private const int MAXIMUM_FIELD_LENGTH = 2048;

    private const int MAXIMUM_MODULUS_BYTES = 1024;

    private const int MAXIMUM_EXPONENT_BYTES = 8;

    /**
     * RFC 7518 §6.3.2's RSA private members, plus §6.4.1's `k`, the
     * symmetric key value. Publishing one is a disclosure, so these are
     * the exception to ignoring what this parser does not read.
     */
    private const array SECRET_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * @return array<string, Key> keyed by LOOKUP_PREFIX . kid, never by the kid itself
     * @throws JwtConfigurationException when $jwksJson is not a JWK Set
     *         of usable RSA verification keys
     */
    public static function parse(#[\SensitiveParameter] string $jwksJson): array
    {
        $document = StrictJson::decodeObject($jwksJson, self::MAXIMUM_JSON_BYTES, self::MAXIMUM_JSON_DEPTH);

        if ($document === null) {
            throw JwtConfigurationException::invalidJwkSet(
                'the document is not a well-formed JSON object within this parser\'s size and nesting '
                . 'limits, or it names the same member twice',
            );
        }

        if (!array_key_exists('keys', $document)) {
            throw JwtConfigurationException::invalidJwkSet('the document has no "keys" member');
        }

        $keys = $document['keys'];

        if (!is_array($keys) || $keys === [] || !array_is_list($keys)) {
            throw JwtConfigurationException::invalidJwkSet('the "keys" member must be a non-empty JSON array');
        }

        if (count($keys) > self::MAXIMUM_KEYS) {
            throw JwtConfigurationException::invalidJwkSet(
                'the document holds more than the ' . self::MAXIMUM_KEYS . ' keys this parser accepts',
            );
        }

        $parsed = [];

        foreach ($keys as $index => $jwk) {
            [$kid, $key] = self::parseKey($index, $jwk);
            $lookupKey = self::LOOKUP_PREFIX . $kid;

            if (isset($parsed[$lookupKey])) {
                throw self::keyRejected($index, 'repeats a kid an earlier key already claims');
            }

            $parsed[$lookupKey] = $key;
        }

        return $parsed;
    }

    /**
     * @return array{0: string, 1: Key}
     */
    private static function parseKey(int $index, #[\SensitiveParameter] mixed $jwk): array
    {
        if (!is_array($jwk) || $jwk === [] || array_is_list($jwk)) {
            throw self::keyRejected($index, 'is not a non-empty JSON object');
        }

        $keyType = $jwk['kty'] ?? null;

        if ($keyType === 'oct') {
            throw self::keyRejected(
                $index,
                'declares kty "oct", whose material is the shared secret itself — a published key set '
                . 'must never carry one',
            );
        }

        if ($keyType !== 'RSA') {
            throw self::keyRejected($index, 'declares a kty other than "RSA", the only type verified here');
        }

        foreach (array_keys($jwk) as $member) {
            if (in_array($member, self::SECRET_MEMBERS, true)) {
                throw self::keyRejected(
                    $index,
                    'carries private or secret key material — a published key set holds public keys only',
                );
            }
        }

        $kid = $jwk['kid'] ?? null;

        if (!JwtKeyValidator::isUsableKidValue($kid)) {
            throw self::keyRejected(
                $index,
                'must carry a kid: non-blank, valid UTF-8, and at most '
                . JwtKeyValidator::MAXIMUM_KID_LENGTH . ' bytes',
            );
        }

        $algorithm = $jwk['alg'] ?? null;

        if (!is_string($algorithm) || !JwtKeyValidator::isRsaAlgorithm($algorithm)) {
            throw self::keyRejected($index, 'must declare an alg of RS256, RS384 or RS512');
        }

        if (array_key_exists('use', $jwk) && $jwk['use'] !== 'sig') {
            throw self::keyRejected($index, 'declares a "use" other than "sig"');
        }

        if (array_key_exists('key_ops', $jwk) && $jwk['key_ops'] !== ['verify']) {
            throw self::keyRejected($index, 'declares key_ops other than exactly ["verify"]');
        }

        $modulus = $jwk['n'] ?? null;
        $exponent = $jwk['e'] ?? null;

        if (!is_string($modulus)) {
            throw self::malformedField($index, 'n');
        }

        if (!is_string($exponent)) {
            throw self::malformedField($index, 'e');
        }

        // The modulus's own size is what JwtKeyValidator checks below,
        // against the key OpenSSL builds from it; decoding it here
        // establishes only that it is a canonical unsigned integer of a
        // bounded length.
        self::decodeRsaInteger($index, 'n', $modulus, self::MAXIMUM_MODULUS_BYTES);
        $exponentBytes = self::decodeRsaInteger($index, 'e', $exponent, self::MAXIMUM_EXPONENT_BYTES);

        // RFC 8017 §3.1: a public exponent is an odd integer, and 1
        // leaves a "signature" every sender can forge.
        $isOdd = (ord($exponentBytes[strlen($exponentBytes) - 1]) & 1) === 1;

        if (!$isOdd || $exponentBytes === "\x01") {
            throw self::malformedField($index, 'e');
        }

        return [$kid, self::buildKey($index, $algorithm, $modulus, $exponent)];
    }

    private static function buildKey(
        int $index,
        string $algorithm,
        #[\SensitiveParameter] string $modulus,
        #[\SensitiveParameter] string $exponent,
    ): Key {
        try {
            $key = JWK::parseKey(['kty' => 'RSA', 'alg' => $algorithm, 'n' => $modulus, 'e' => $exponent]);
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException) {
            $key = null;
        }

        if ($key === null) {
            throw self::unusableKey($index);
        }

        try {
            JwtKeyValidator::assertRsaPublicKey($algorithm, $key->getKeyMaterial());
        } catch (JwtConfigurationException) {
            throw self::unusableKey($index);
        }

        return $key;
    }

    /**
     * Decodes one RSA integer field from its RFC 7518 §6.3.1 form: a
     * canonical base64url spelling (see Base64Url) within a bounded
     * length, decoding to a byte string with no leading zero byte, which
     * would write the same number a second way.
     */
    private static function decodeRsaInteger(
        int $index,
        string $field,
        #[\SensitiveParameter] string $value,
        int $maximumBytes,
    ): string {
        $decoded = strlen($value) > self::MAXIMUM_FIELD_LENGTH ? null : Base64Url::decode($value);

        if ($decoded === null || strlen($decoded) > $maximumBytes || $decoded[0] === "\x00") {
            throw self::malformedField($index, $field);
        }

        return $decoded;
    }

    private static function malformedField(int $index, string $field): JwtConfigurationException
    {
        return self::keyRejected(
            $index,
            "has a malformed \"{$field}\" — an RSA {$field} must be the canonical unpadded base64url "
            . 'spelling of an unsigned integer of the expected size',
        );
    }

    private static function unusableKey(int $index): JwtConfigurationException
    {
        return self::keyRejected(
            $index,
            'does not compose into a usable RSA public key of at least the '
            . JwtKeyValidator::RSA_MINIMUM_BITS . '-bit minimum verified with here',
        );
    }

    /**
     * A JWKS reaching this parser is untrusted input, so a rejection
     * names the rule and the zero-based position of the offending key,
     * and no part of the input itself.
     */
    private static function keyRejected(int $index, string $detail): JwtConfigurationException
    {
        return JwtConfigurationException::invalidJwkSet("the key at index {$index} {$detail}");
    }
}
