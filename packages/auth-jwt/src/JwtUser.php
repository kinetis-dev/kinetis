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
 */
final readonly class JwtUser implements CurrentUserInterface
{
    public function __construct(
        private stdClass $claims,
    ) {}

    #[\Override]
    public function id(): string|int
    {
        $sub = $this->claims->sub ?? null;

        if (!is_string($sub) && !is_int($sub)) {
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
