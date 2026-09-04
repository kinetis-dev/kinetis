<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

/**
 * Canonical cache-key material for a subject id.
 *
 * A subject id reaches this package as `string|int` — RevocationStore
 * and RefreshTokenStore both accept either — and the two are separate
 * identities: an app keying its users by integer id and an app keying
 * them by opaque string id can both be right, but `123` and `'123'` are
 * not the same user in either of them. Hashing a bare `(string)` cast
 * folds both onto one key, so revoking one identity revokes the other.
 *
 * digest() frames the value with its own type tag before hashing, which
 * keeps the two apart while leaving equal values of the same type on one
 * stable key. The tag is unconditional, so no string can be framed into
 * an integer's shape: the string `'i:1'` frames as `s:i:1`, never as the
 * integer `1`'s `i:1`. An integer's decimal form carries no `:` of its
 * own, so nothing after the tag needs escaping either.
 *
 * The digest is a fixed-width hex string, so a caller's own prefix (see
 * both stores' `userKey()`) can be concatenated onto it directly without
 * the value bleeding into the prefix.
 *
 * `@internal` is documentation, not an access boundary — this is a
 * key-derivation detail shared by the two stores, not part of either
 * one's public contract.
 *
 * @internal
 */
final class SubjectKey
{
    public static function digest(string|int $subject): string
    {
        return hash('sha256', is_int($subject) ? 'i:' . $subject : 's:' . $subject);
    }
}
