<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use ArrayAccess;
use BadMethodCallException;
use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Kinetis\AuthJwt\Exception\ParsedJwkSetException;
use UnexpectedValueException;

/**
 * An RFC 7517 JWK Set parsed from raw JSON into the verification keys
 * JwtAuthMiddleware selects among — the consuming half of what JwkSet
 * publishes, and this package's path from a JWKS document to a
 * configured middleware.
 *
 * A kid is an opaque string; a PHP array key is not, since `"0"` used
 * as one becomes the integer 0. Lookup here runs through ArrayAccess
 * over an internal map whose keys all carry a fixed non-numeric prefix,
 * so `"0"`, `"00"` and `"zero"` are three separately selectable keys,
 * each reached by the exact string the document published, and kids()
 * returns them spelled the way they arrived. Firebase\JWT\JWT::decode()
 * accepts an ArrayAccess key set directly.
 *
 * fromJson() returns a set whose every key is usable, or throws — never
 * a partial set with the failing keys dropped. RFC 7517 §5 allows a
 * document to carry members beyond the ones a reader understands and
 * requires those to be ignored, so an unrecognized member at the root
 * or inside a key is skipped; every member this package does read is
 * enforced, and published private or symmetric material is refused.
 * Exception\ParsedJwkSetException states each rule.
 *
 * Size limits are fixed rather than configurable, so how much this
 * package decodes stays its own decision rather than the sender's.
 *
 * @implements ArrayAccess<string, Key>
 */
final class ParsedJwkSet implements ArrayAccess
{
    public const int MAXIMUM_JSON_BYTES = 65536;

    public const int MAXIMUM_KEYS = 32;

    private const int MAXIMUM_JSON_DEPTH = 8;

    /**
     * Long enough for the base64url form of an 8192-bit modulus, four
     * times the minimum this package accepts.
     */
    private const int MAXIMUM_FIELD_LENGTH = 2048;

    private const int MAXIMUM_MODULUS_BYTES = 1024;

    private const int MAXIMUM_EXPONENT_BYTES = 8;

    /**
     * Prefixes every kid before it becomes an array key. A fixed
     * non-numeric literal is injective and leaves no kid in the
     * canonical decimal form PHP would coerce to an integer.
     */
    private const string LOOKUP_PREFIX = 'kid#';

    /**
     * RFC 7518 §6.3.2's RSA private members, plus §6.4.1's `k`, the
     * symmetric key value. Publishing one is a disclosure, so these are
     * the exception to ignoring what this parser does not read.
     */
    private const array SECRET_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * @param array<string, Key> $keysByLookupKey keyed by LOOKUP_PREFIX . kid, never by the kid itself
     * @param list<string> $kids the exact kid strings, in document order
     */
    private function __construct(
        private array $keysByLookupKey,
        private array $kids,
    ) {}

    /**
     * @throws ParsedJwkSetException when $jwksJson is not a JWK Set of
     *         usable RSA verification keys
     */
    public static function fromJson(#[\SensitiveParameter] string $jwksJson): self
    {
        $document = StrictJson::decodeObject($jwksJson, self::MAXIMUM_JSON_BYTES, self::MAXIMUM_JSON_DEPTH);

        if ($document === null) {
            throw ParsedJwkSetException::malformedDocument();
        }

        if (!array_key_exists('keys', $document)) {
            throw ParsedJwkSetException::missingKeysMember();
        }

        $keys = $document['keys'];

        if (!is_array($keys) || $keys === [] || !array_is_list($keys)) {
            throw ParsedJwkSetException::malformedKeysMember();
        }

        if (count($keys) > self::MAXIMUM_KEYS) {
            throw ParsedJwkSetException::tooManyKeys(self::MAXIMUM_KEYS);
        }

        $keysByLookupKey = [];
        $kids = [];

        foreach ($keys as $index => $jwk) {
            [$kid, $key] = self::parseKey($index, $jwk);
            $lookupKey = self::LOOKUP_PREFIX . $kid;

            if (isset($keysByLookupKey[$lookupKey])) {
                throw ParsedJwkSetException::duplicateKid($index);
            }

            $keysByLookupKey[$lookupKey] = $key;
            $kids[] = $kid;
        }

        return new self($keysByLookupKey, $kids);
    }

    /**
     * The exact kid strings this set holds, in the order the document
     * listed them.
     *
     * @return list<string>
     */
    public function kids(): array
    {
        return $this->kids;
    }

    public function has(string $kid): bool
    {
        return isset($this->keysByLookupKey[self::LOOKUP_PREFIX . $kid]);
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    /**
     * Raises what JWT::decode() raises for a kid it cannot look up,
     * which JwtAuthMiddleware already answers with a 401.
     */
    #[\Override]
    public function offsetGet(mixed $offset): Key
    {
        if (!is_string($offset) || !$this->has($offset)) {
            throw new UnexpectedValueException('No key in this JWK Set carries the requested kid.');
        }

        return $this->keysByLookupKey[self::LOOKUP_PREFIX . $offset];
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException(
            'A ParsedJwkSet is read-only — parse a new set from the published JWKS instead of writing '
            . 'into a verified one.',
        );
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException(
            'A ParsedJwkSet is read-only — parse a new set from the published JWKS instead of removing '
            . 'a key from a verified one.',
        );
    }

    /**
     * @return array{0: string, 1: Key}
     */
    private static function parseKey(int $index, #[\SensitiveParameter] mixed $jwk): array
    {
        if (!is_array($jwk) || $jwk === [] || array_is_list($jwk)) {
            throw ParsedJwkSetException::keyNotAnObject($index);
        }

        $keyType = $jwk['kty'] ?? null;

        if ($keyType === 'oct') {
            throw ParsedJwkSetException::symmetricKey($index);
        }

        if ($keyType !== 'RSA') {
            throw ParsedJwkSetException::unsupportedKeyType($index);
        }

        foreach (array_keys($jwk) as $member) {
            if (in_array($member, self::SECRET_MEMBERS, true)) {
                throw ParsedJwkSetException::privateKeyMaterial($index);
            }
        }

        $kid = $jwk['kid'] ?? null;

        if (!JwtKeyValidator::isUsableKidValue($kid)) {
            throw ParsedJwkSetException::invalidKid($index);
        }

        $algorithm = $jwk['alg'] ?? null;

        if (!is_string($algorithm) || !JwtKeyValidator::isRsaAlgorithm($algorithm)) {
            throw ParsedJwkSetException::unsupportedAlgorithm($index);
        }

        if (array_key_exists('use', $jwk) && $jwk['use'] !== 'sig') {
            throw ParsedJwkSetException::unsupportedKeyUse($index);
        }

        if (array_key_exists('key_ops', $jwk) && $jwk['key_ops'] !== ['verify']) {
            throw ParsedJwkSetException::unsupportedKeyOperations($index);
        }

        $modulus = $jwk['n'] ?? null;
        $exponent = $jwk['e'] ?? null;

        if (!is_string($modulus)) {
            throw ParsedJwkSetException::invalidKeyField($index, 'n');
        }

        if (!is_string($exponent)) {
            throw ParsedJwkSetException::invalidKeyField($index, 'e');
        }

        // The modulus's own size is what JwtKeyValidator checks below,
        // against the key OpenSSL builds from it; decoding it here
        // establishes only that it is a canonical unsigned integer of
        // a bounded length.
        self::decodeRsaInteger($index, 'n', $modulus, self::MAXIMUM_MODULUS_BYTES);
        $exponentBytes = self::decodeRsaInteger($index, 'e', $exponent, self::MAXIMUM_EXPONENT_BYTES);

        // RFC 8017 §3.1: a public exponent is an odd integer, and 1
        // leaves a "signature" every sender can forge.
        $isOdd = (ord($exponentBytes[strlen($exponentBytes) - 1]) & 1) === 1;

        if (!$isOdd || $exponentBytes === "\x01") {
            throw ParsedJwkSetException::invalidKeyField($index, 'e');
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
            // The three types JWK::parseKey() documents, none chained on
            // — see Exception\ParsedJwkSetException.
            throw ParsedJwkSetException::unusableKey($index);
        }

        if ($key === null) {
            throw ParsedJwkSetException::unusableKey($index);
        }

        JwtKeyValidator::assertKeyMaterial(
            $algorithm,
            $key->getKeyMaterial(),
            'public',
            static fn () => ParsedJwkSetException::unusableKey($index),
        );

        return $key;
    }

    /**
     * Decodes one RSA integer field from its RFC 7518 §6.3.1 form: a
     * canonical base64url spelling (see Base64Url) within a bounded
     * length, decoding to a byte string with no leading zero byte,
     * which would write the same number a second way.
     */
    private static function decodeRsaInteger(
        int $index,
        string $field,
        #[\SensitiveParameter] string $value,
        int $maximumBytes,
    ): string {
        $decoded = strlen($value) > self::MAXIMUM_FIELD_LENGTH ? null : Base64Url::decode($value);

        if ($decoded === null || strlen($decoded) > $maximumBytes || $decoded[0] === "\x00") {
            throw ParsedJwkSetException::invalidKeyField($index, $field);
        }

        return $decoded;
    }
}
