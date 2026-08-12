<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Firebase\JWT\JWT;

/**
 * Stateless by design — no storage, since that's the entire point of a
 * JWT. Signs claims with the key JwtAuthMiddleware verifies against — the
 * *same* key for a symmetric algorithm (`HS256`/`HS384`/`HS512`), or the
 * *private* half of a key pair for an asymmetric one (`RS256`/`RS384`/
 * `RS512`), passed as a PEM-format string; JwtAuthMiddleware takes the
 * public half in that case. Issuing a token to a user (verifying a
 * password, calling this, returning the result to the client) is your own
 * login endpoint's job; this only covers "given a subject, produce a
 * signed token."
 *
 * Neither this class nor JwtAuthMiddleware sets or checks `iss`/`aud` —
 * if two separate services share the same HS256 secret, each will accept
 * a token the other one issued, with nothing here to stop it. Pass your
 * own `iss`/`aud` values through $claims and verify them in your own
 * application code (or give each service its own secret/key pair
 * instead) if that's not what you want.
 */
final readonly class JwtIssuer
{
    public function __construct(
        private string $key,
        private string $algorithm = 'HS256',
    ) {}

    /**
     * @param array<string, mixed> $claims extra claims merged in alongside `sub`/`iat`/`exp`/`jti`, which always win if duplicated
     */
    public function issue(string|int $subject, array $claims = [], ?int $ttlSeconds = 3600): string
    {
        $now = time();
        $payload = [...$claims, 'sub' => (string) $subject, 'iat' => $now, 'jti' => bin2hex(random_bytes(16))];

        if ($ttlSeconds !== null) {
            $payload['exp'] = $now + $ttlSeconds;
        }

        return JWT::encode($payload, $this->key, $this->algorithm);
    }
}
