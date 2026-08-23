<?php

declare(strict_types=1);

namespace Kinetis\Auth;

/**
 * A single correct answer for "how do I store a user's password" — a
 * thin wrapper over password_hash()/password_verify()/
 * password_needs_rehash(), all using PASSWORD_DEFAULT rather than a
 * pinned algorithm constant, so hashing here tracks whatever PHP itself
 * currently recommends instead of requiring a Kinetis release to keep
 * up.
 *
 * Storage, verification, and login-endpoint wiring are the app's own
 * concern — this covers only the three primitives. See
 * TokenGenerator's own docblock for why a bearer token uses a
 * different, faster mechanism than a human password.
 */
final class PasswordHasher
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
