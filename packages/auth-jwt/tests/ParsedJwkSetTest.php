<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use BadMethodCallException;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Base64Url;
use Kinetis\AuthJwt\Exception\ParsedJwkSetException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\ParsedJwkSet;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\SecondRsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ParsedJwkSetTest extends TestCase
{
    /**
     * One JWK exactly as this package publishes it.
     *
     * @return array<string, mixed>
     */
    private static function jwk(string $publicKey, string $kid, string $algorithm = 'RS256'): array
    {
        return JwkSet::fromRsaPublicKeys([new PublishedRsaKey($kid, $publicKey)], $algorithm)['keys'][0];
    }

    /**
     * Rewrites a base64url string into a second spelling of the same
     * bytes (see Base64Url), by setting an unused low bit in its final
     * character. Only meaningful for a length of 4n+2 or 4n+3.
     */
    private static function withUnusedBitsSet(string $base64Url): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $index = strpos($alphabet, $base64Url[strlen($base64Url) - 1]);
        self::assertIsInt($index);

        return substr($base64Url, 0, -1) . $alphabet[$index + 1];
    }

    /**
     * Builds a JWK straight from a key's own modulus and exponent,
     * without JwkSet's publishing rules — the only way to hand the
     * parser a key JwkSet itself refuses to publish, such as an
     * undersized one.
     *
     * @return array<string, mixed>
     */
    private static function rawJwk(string $publicKey, string $kid, string $algorithm = 'RS256'): array
    {
        $key = openssl_pkey_get_public($publicKey);
        self::assertNotFalse($key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa']);

        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => $algorithm,
            'n' => $encode((string) $details['rsa']['n']),
            'e' => $encode((string) $details['rsa']['e']),
        ];
    }

    /**
     * @param array<string, mixed> ...$keys
     */
    private static function document(array ...$keys): string
    {
        return json_encode(['keys' => $keys], JSON_THROW_ON_ERROR);
    }

    private static function publicKeyPem(Key $key): string
    {
        $material = $key->getKeyMaterial();
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $material);

        $details = openssl_pkey_get_details($material);
        self::assertIsArray($details);

        return trim((string) $details['key']);
    }

    private static function normalizedPem(string $pem): string
    {
        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return trim((string) $details['key']);
    }

    public function test_a_published_key_set_round_trips_into_the_keys_it_published(): void
    {
        $document = json_encode(
            JwkSet::fromRsaPublicKeys([
                new PublishedRsaKey('2025-key', RsaKeyPair::PUBLIC_KEY),
                new PublishedRsaKey('2026-key', SecondRsaKeyPair::publicKey()),
            ]),
            JSON_THROW_ON_ERROR,
        );

        $set = ParsedJwkSet::fromJson($document);

        self::assertSame(['2025-key', '2026-key'], $set->kids());
        self::assertSame(self::normalizedPem(RsaKeyPair::PUBLIC_KEY), self::publicKeyPem($set['2025-key']));
        self::assertSame(self::normalizedPem(SecondRsaKeyPair::publicKey()), self::publicKeyPem($set['2026-key']));
        self::assertSame('RS256', $set['2025-key']->getAlgorithm());
    }

    public function test_the_kids_0_and_00_select_their_own_keys_and_nothing_else(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(
            self::jwk(RsaKeyPair::PUBLIC_KEY, '0'),
            self::jwk(SecondRsaKeyPair::publicKey(), '00'),
        ));

        self::assertSame(['0', '00'], $set->kids());
        self::assertTrue($set->has('0'));
        self::assertTrue($set->has('00'));
        self::assertFalse($set->has('000'));
        self::assertFalse($set->has(''));
        self::assertSame(self::normalizedPem(RsaKeyPair::PUBLIC_KEY), self::publicKeyPem($set['0']));
        self::assertSame(self::normalizedPem(SecondRsaKeyPair::publicKey()), self::publicKeyPem($set['00']));
    }

    public function test_a_decimal_kid_and_ordinary_text_stay_separate_keys(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(
            self::jwk(RsaKeyPair::PUBLIC_KEY, '0'),
            self::jwk(SecondRsaKeyPair::publicKey(), 'zero'),
        ));

        self::assertSame(['0', 'zero'], $set->kids());
        self::assertNotSame(self::publicKeyPem($set['0']), self::publicKeyPem($set['zero']));
    }

    public function test_a_kid_claimed_twice_is_rejected_before_any_set_exists(): void
    {
        $this->expectException(ParsedJwkSetException::class);
        $this->expectExceptionMessage('index 1 repeats a kid');

        ParsedJwkSet::fromJson(self::document(
            self::jwk(RsaKeyPair::PUBLIC_KEY, 'shared'),
            self::jwk(SecondRsaKeyPair::publicKey(), 'shared'),
        ));
    }

    /**
     * A document whose second key is unusable produces no set at all,
     * not one holding only the first.
     */
    public function test_one_unusable_key_refuses_the_whole_document(): void
    {
        $this->expectException(ParsedJwkSetException::class);
        $this->expectExceptionMessage('index 1');

        ParsedJwkSet::fromJson(self::document(
            self::jwk(RsaKeyPair::PUBLIC_KEY, 'good'),
            self::rawJwk(UndersizedRsaKeyPair::PUBLIC_KEY, 'undersized'),
        ));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedDocuments(): array
    {
        return [
            'not JSON at all' => ['nonsense', 'not a well-formed JSON object'],
            'a JSON array at the root' => ['[]', 'not a well-formed JSON object'],
            'a JSON string at the root' => ['"keys"', 'not a well-formed JSON object'],
            'empty input' => ['', 'not a well-formed JSON object'],
            'a truncated object' => ['{"keys":[', 'not a well-formed JSON object'],
            'a repeated root member' => ['{"keys":[],"keys":[]}', 'not a well-formed JSON object'],
            'a root member repeated under an escaped spelling' => [
                '{"keys":[],"\u006beys":[]}',
                'not a well-formed JSON object',
            ],
            'a repeated member inside a key' => [
                '{"keys":[{"kty":"RSA","kty":"oct"}]}',
                'not a well-formed JSON object',
            ],
            'more bytes than the parser accepts' => [
                '{"keys":[' . str_repeat(' ', ParsedJwkSet::MAXIMUM_JSON_BYTES) . ']}',
                'not a well-formed JSON object',
            ],
            'a root object with no keys member' => ['{"extra":1}', 'has no "keys" member'],
            'no keys member' => ['{}', 'has no "keys" member'],
            'a keys member that is an object' => ['{"keys":{"a":1}}', 'must be a non-empty JSON array'],
            'a keys member that is a string' => ['{"keys":"a"}', 'must be a non-empty JSON array'],
            'an empty keys list' => ['{"keys":[]}', 'must be a non-empty JSON array'],
            'more keys than the parser accepts' => [
                json_encode(
                    ['keys' => array_fill(0, ParsedJwkSet::MAXIMUM_KEYS + 1, ['kty' => 'RSA'])],
                    JSON_THROW_ON_ERROR,
                ),
                'holds more than the ' . ParsedJwkSet::MAXIMUM_KEYS . ' keys',
            ],
            'a key that is a string' => ['{"keys":["a"]}', 'index 0 is not a non-empty JSON object'],
            'a key that is a list' => ['{"keys":[[1,2]]}', 'index 0 is not a non-empty JSON object'],
            'a key that is empty' => ['{"keys":[{}]}', 'index 0 is not a non-empty JSON object'],
        ];
    }

    #[DataProvider('malformedDocuments')]
    public function test_a_malformed_document_is_rejected(string $document, string $expectedMessage): void
    {
        $this->expectException(ParsedJwkSetException::class);
        $this->expectExceptionMessage($expectedMessage);

        ParsedJwkSet::fromJson($document);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedKeys(): array
    {
        $valid = self::jwk(RsaKeyPair::PUBLIC_KEY, 'a');

        $without = static function (string $member) use ($valid): array {
            unset($valid[$member]);

            return $valid;
        };

        $with = static fn (array $members): array => $members + $valid;

        return [
            'a symmetric key' => [
                ['kty' => 'oct', 'kid' => 'a', 'alg' => 'HS256', 'k' => 'c2VjcmV0'],
                'declares kty "oct"',
            ],
            'an elliptic-curve key' => [
                ['kty' => 'EC', 'kid' => 'a', 'alg' => 'ES256', 'crv' => 'P-256', 'x' => 'AQ', 'y' => 'AQ'],
                'declares a kty ParsedJwkSet does not verify with',
            ],
            'no kty' => [$without('kty'), 'declares a kty ParsedJwkSet does not verify with'],
            'a kty that is an array' => [$with(['kty' => ['RSA']]), 'declares a kty ParsedJwkSet does not verify with'],
            'an RSA private exponent' => [$with(['d' => 'AQAB']), 'carries private or secret key material'],
            'an RSA prime factor' => [$with(['p' => 'AQAB']), 'carries private or secret key material'],
            'a symmetric key value' => [$with(['k' => 'AQAB']), 'carries private or secret key material'],
            'no kid' => [$without('kid'), 'must carry a kid'],
            'a kid that is an integer' => [$with(['kid' => 7]), 'must carry a kid'],
            'a kid that is null' => [$with(['kid' => null]), 'must carry a kid'],
            'a kid that is an array' => [$with(['kid' => ['a']]), 'must carry a kid'],
            'an empty kid' => [$with(['kid' => '']), 'must carry a kid'],
            'a blank kid' => [$with(['kid' => "  \t"]), 'must carry a kid'],
            'a kid past the length limit' => [
                $with(['kid' => str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1)]),
                'must carry a kid',
            ],
            'no alg' => [$without('alg'), 'must declare an alg of RS256, RS384 or RS512'],
            'an HMAC alg' => [$with(['alg' => 'HS256']), 'must declare an alg of RS256, RS384 or RS512'],
            'an unsupported alg' => [$with(['alg' => 'PS256']), 'must declare an alg of RS256, RS384 or RS512'],
            'an alg that is an array' => [$with(['alg' => ['RS256']]), 'must declare an alg of RS256, RS384 or RS512'],
            'a use of enc' => [$with(['use' => 'enc']), 'declares a "use" other than "sig"'],
            'a use that is an array' => [$with(['use' => ['sig']]), 'declares a "use" other than "sig"'],
            'key_ops naming signing' => [$with(['key_ops' => ['sign']]), 'declares key_ops other than exactly'],
            'key_ops naming more than verify' => [
                $with(['key_ops' => ['verify', 'sign']]),
                'declares key_ops other than exactly',
            ],
            'no n' => [$without('n'), 'has a malformed "n"'],
            'an n that is an integer' => [$with(['n' => 65537]), 'has a malformed "n"'],
            'an n with base64 padding' => [$with(['n' => 'AQAB==']), 'has a malformed "n"'],
            'an n outside the base64url alphabet' => [$with(['n' => 'AQ+B']), 'has a malformed "n"'],
            'an n of an impossible length' => [$with(['n' => 'AQABC']), 'has a malformed "n"'],
            'an n with a leading zero byte' => [$with(['n' => 'AAEAAQ']), 'has a malformed "n"'],
            'an n past the length limit' => [$with(['n' => str_repeat('A', 2049)]), 'has a malformed "n"'],
            'no e' => [$without('e'), 'has a malformed "e"'],
            'an even e' => [$with(['e' => 'AQAC']), 'has a malformed "e"'],
            'an e of 1' => [$with(['e' => 'AQ']), 'has a malformed "e"'],
            'an e past the exponent limit' => [$with(['e' => 'AQABAQABAQAB']), 'has a malformed "e"'],
            'an undersized modulus' => [
                self::rawJwk(UndersizedRsaKeyPair::PUBLIC_KEY, 'a'),
                'does not compose into a usable RSA public key',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $key
     */
    #[DataProvider('malformedKeys')]
    public function test_a_malformed_key_is_rejected(array $key, string $expectedMessage): void
    {
        $this->expectException(ParsedJwkSetException::class);
        $this->expectExceptionMessage($expectedMessage);

        ParsedJwkSet::fromJson(self::document($key));
    }

    /**
     * Members ParsedJwkSet does not read, at the root and inside a key,
     * including the certificate metadata providers commonly publish.
     *
     * @return array<string, array{string}>
     */
    public static function ignoredMetadata(): array
    {
        $key = self::jwk(RsaKeyPair::PUBLIC_KEY, 'a');
        $encode = static fn (array $document): string => json_encode($document, JSON_THROW_ON_ERROR);

        return [
            'a root member beside keys' => [$encode(['keys' => [$key], 'timestamp' => 1_700_000_000])],
            'a root member before keys' => [$encode(['issuer' => 'https://example.test', 'keys' => [$key]])],
            'a certificate chain' => [$encode(['keys' => [$key + ['x5c' => ['MIIB']]]])],
            'a certificate thumbprint' => [$encode(['keys' => [$key + ['x5t' => 'abc']]])],
            'a SHA-256 certificate thumbprint' => [$encode(['keys' => [$key + ['x5t#S256' => 'abc']]])],
            'a certificate URL' => [$encode(['keys' => [$key + ['x5u' => 'https://example.test/c.pem']]])],
            'an extension member' => [$encode(['keys' => [$key + ['ext' => true]]])],
        ];
    }

    #[DataProvider('ignoredMetadata')]
    public function test_a_member_this_parser_does_not_understand_is_ignored(string $document): void
    {
        $set = ParsedJwkSet::fromJson($document);

        self::assertSame(['a'], $set->kids());
        self::assertSame(self::normalizedPem(RsaKeyPair::PUBLIC_KEY), self::publicKeyPem($set['a']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function acceptedMetadata(): array
    {
        $valid = self::jwk(RsaKeyPair::PUBLIC_KEY, 'a');

        return [
            'use: sig' => [['use' => 'sig'] + $valid],
            'key_ops: ["verify"]' => [['key_ops' => ['verify']] + $valid],
            'RS384' => [self::jwk(RsaKeyPair::PUBLIC_KEY, 'a', 'RS384')],
            'RS512' => [self::jwk(RsaKeyPair::PUBLIC_KEY, 'a', 'RS512')],
        ];
    }

    /**
     * @param array<string, mixed> $key
     */
    #[DataProvider('acceptedMetadata')]
    public function test_standard_metadata_this_parser_understands_is_accepted(array $key): void
    {
        $set = ParsedJwkSet::fromJson(self::document($key));

        self::assertSame(['a'], $set->kids());
    }

    public function test_a_rejection_message_names_no_part_of_the_document(): void
    {
        $kid = 'kid-that-must-not-be-echoed';
        $key = self::rawJwk(UndersizedRsaKeyPair::PUBLIC_KEY, $kid);

        try {
            ParsedJwkSet::fromJson(self::document($key));
            self::fail('Expected a ParsedJwkSetException.');
        } catch (ParsedJwkSetException $exception) {
            $modulus = (string) $key['n'];

            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($kid, $exception->getMessage());
            self::assertStringNotContainsString($modulus, $exception->getMessage());
            self::assertStringNotContainsString('OpenSSL', $exception->getMessage());
            self::assertStringNotContainsString($kid, (string) $exception);
            self::assertStringNotContainsString($modulus, (string) $exception);
            self::assertStringNotContainsString(substr($modulus, 0, 12), (string) $exception);
        }
    }

    public function test_a_key_set_cannot_be_written_into(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(self::jwk(RsaKeyPair::PUBLIC_KEY, 'a')));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('read-only');

        $set['b'] = new Key(RsaKeyPair::PUBLIC_KEY, 'RS256');
    }

    public function test_a_key_cannot_be_removed_from_a_key_set(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(self::jwk(RsaKeyPair::PUBLIC_KEY, 'a')));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('read-only');

        unset($set['a']);
    }

    public function test_reading_an_absent_kid_directly_fails_the_way_a_decode_lookup_does(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(self::jwk(RsaKeyPair::PUBLIC_KEY, 'a')));

        self::assertFalse(isset($set['b']));

        $this->expectException(UnexpectedValueException::class);

        self::assertInstanceOf(Key::class, $set['b']);
    }

    public function test_a_non_string_offset_matches_nothing(): void
    {
        $set = ParsedJwkSet::fromJson(self::document(self::jwk(RsaKeyPair::PUBLIC_KEY, '0')));

        self::assertFalse($set->offsetExists(0));
        self::assertTrue($set->offsetExists('0'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rsaIntegerFields(): array
    {
        return [['n'], ['e']];
    }

    /**
     * Both spellings decode to the same bytes, which the test asserts
     * before requiring the non-canonical one to be refused.
     */
    #[DataProvider('rsaIntegerFields')]
    public function test_a_non_canonical_spelling_of_an_rsa_integer_is_rejected(string $field): void
    {
        // A 2048-bit modulus encodes to 4n+2 characters and this
        // exponent to 4n+3, so both carry unused bits to set.
        $key = ['e' => 'AQE'] + self::jwk(RsaKeyPair::PUBLIC_KEY, 'a');
        self::assertSame(['a'], ParsedJwkSet::fromJson(self::document($key))->kids());

        $canonical = (string) $key[$field];
        $key[$field] = self::withUnusedBitsSet($canonical);

        self::assertNotSame($canonical, $key[$field]);
        self::assertSame(Base64Url::decode($canonical), base64_decode(strtr($key[$field], '-_', '+/')));

        $this->expectException(ParsedJwkSetException::class);
        $this->expectExceptionMessage("has a malformed \"{$field}\"");

        ParsedJwkSet::fromJson(self::document($key));
    }
}
