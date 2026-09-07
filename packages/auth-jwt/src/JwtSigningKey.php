<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Firebase\JWT\JWT;

/**
 * The key JwtIssuer signs with, and the one place a signing algorithm
 * and `kid` are named. Immutable, holds no request state, and validates
 * everything at construction — never on the first issue() call.
 *
 * hmacSecret() takes the shared secret a symmetric algorithm
 * (HS256/HS384/HS512) uses on both sides. rsaPrivateKey() takes the
 * *private* half of an RSA key pair as a PEM string, for RS256/RS384/
 * RS512; JwtVerificationKeys takes the public half. There is no form
 * that puts a private key into a verifier, which is the entire reason to
 * choose an asymmetric algorithm.
 *
 * $kid is written into every token's own header. Pair it with a
 * JwtVerificationKeys::jwks() set to roll a signing key over without
 * invalidating the tokens issued under the previous one. Null omits the
 * header.
 *
 * The key material stays inside this value: sign() is the only thing
 * that reads it.
 */
final readonly class JwtSigningKey
{
    private function __construct(
        private string $algorithm,
        private string $material,
        private ?string $kid,
    ) {}

    public static function hmacSecret(
        #[\SensitiveParameter] string $secret,
        string $algorithm = 'HS256',
        ?string $kid = null,
    ): self {
        JwtKeyValidator::assertHmacSecret($algorithm, $secret);

        return new self($algorithm, $secret, self::checkedKid($kid));
    }

    public static function rsaPrivateKey(
        #[\SensitiveParameter] string $privateKeyPem,
        string $algorithm = 'RS256',
        ?string $kid = null,
    ): self {
        JwtKeyValidator::assertRsaPrivateKey($algorithm, $privateKeyPem);

        return new self($algorithm, $privateKeyPem, self::checkedKid($kid));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        return JWT::encode($payload, $this->material, $this->algorithm, $this->kid);
    }

    private static function checkedKid(?string $kid): ?string
    {
        if ($kid !== null) {
            JwtKeyValidator::assertUsableKid($kid);
        }

        return $kid;
    }
}
