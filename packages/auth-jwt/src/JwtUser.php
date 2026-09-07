<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\Http\CurrentUserInterface;
use stdClass;
use UnexpectedValueException;

/**
 * Wraps a verified JWT's decoded claims. id() reads the standard `sub`
 * (subject) claim; claim()/claims() expose everything else the token
 * carries (roles, email, ...) without this package needing to know their
 * shape ahead of time — the same "presence is the signal, contents are
 * the app's business" spirit CurrentUserInterface itself documents.
 *
 * id() narrows CurrentUserInterface's own `string|int` to the non-empty
 * string a subject always is here (see JwtIssuer) — the same identity
 * RefreshTokenStore::redeem() hands back, so one application id names
 * one user across the access token and the refresh token alike.
 * JwtAuthMiddleware rejects a token whose `sub` is anything else, so a
 * JwtUser it registered always has one.
 */
final readonly class JwtUser implements CurrentUserInterface
{
    public function __construct(
        private stdClass $claims,
    ) {}

    #[\Override]
    public function id(): string
    {
        $sub = $this->claims->sub ?? null;

        if (!is_string($sub) || $sub === '') {
            throw new UnexpectedValueException('JWT claims have no valid "sub" (subject) claim.');
        }

        return $sub;
    }

    public function claim(string $name): mixed
    {
        return $this->claims->{$name} ?? null;
    }

    public function claims(): stdClass
    {
        return $this->claims;
    }
}
