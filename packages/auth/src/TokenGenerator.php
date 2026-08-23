<?php

declare(strict_types=1);

namespace Kinetis\Auth;

/**
 * A single correct answer for "how do I generate a bearer token" — plain
 * CSPRNG bytes (random_bytes()), not uniqid()/rand()/mt_rand(), hex-encoded
 * so the result is safe to place directly in an Authorization header with
 * no escaping.
 *
 * Generation only. Storage is the app's own concern — see
 * UserProviderInterface's docblock for the hashing convention this pairs
 * with.
 */
final class TokenGenerator
{
    /**
     * @param int<1, max> $bytes
     */
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
