<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use RuntimeException;

/**
 * A second RSA key pair, distinct from RsaKeyPair, so a test can tell
 * which of two published keys a kid selected.
 *
 * The pair is generated when a test first asks for it, so this suite
 * carries no private key material of its own. Both halves come from one
 * openssl_pkey_new() call held for the life of the process: a token
 * signed in one test is verified against the same public half in
 * another, and generating the key once keeps its cost off every test
 * that reads it.
 */
final class SecondRsaKeyPair
{
    /**
     * JwtKeyValidator::RSA_MINIMUM_BITS, so this pair is accepted
     * wherever a published RSA key is, and no larger: generation time
     * climbs steeply with key size and buys a test nothing.
     */
    private const int KEY_BITS = 2048;

    /** @var array{private: string, public: string}|null */
    private static ?array $pair = null;

    public static function privateKey(): string
    {
        return self::pair()['private'];
    }

    public static function publicKey(): string
    {
        return self::pair()['public'];
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function pair(): array
    {
        if (self::$pair !== null) {
            return self::$pair;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || !openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Could not generate the second RSA key pair this suite tests against.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read the second RSA key pair this suite tests against.');
        }

        return self::$pair = ['private' => $privateKey, 'public' => $details['key']];
    }
}
