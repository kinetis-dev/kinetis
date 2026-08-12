<?php

declare(strict_types=1);

namespace Kinetis\Auth;

use Kinetis\Http\CurrentUserInterface;

/**
 * Storage-agnostic on purpose — this package has no opinion on how or
 * where tokens are kept (a table via kinetis/query-builder, raw SQL, a
 * cache, an external identity service). The app implements this once,
 * against whatever it already uses.
 *
 * Recommended convention for implementers, not enforced by this
 * interface: store hash('sha256', $token) rather than the raw token, and
 * look up by that same hash here. Don't reach for password_hash()/bcrypt
 * for this — a bearer token is already high-entropy random data (see
 * TokenGenerator), not a low-entropy human password, so a slow KDF only
 * adds latency to every request's lookup with no security benefit.
 */
interface UserProviderInterface
{
    public function findByToken(string $token): ?CurrentUserInterface;
}
