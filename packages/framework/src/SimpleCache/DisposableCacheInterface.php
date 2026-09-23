<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

/**
 * Releasing the connection a cache opened for itself — a capability a
 * cache has only when it was built with a transport nobody else owns.
 *
 * A cache lives for the whole worker, so its connection is
 * application-scoped and has to be closed once when that worker ends.
 * `AppScope::boot()` registers dispose() for the default cache it
 * constructs, and for no other instance: a cache the application binds
 * itself belongs to that application, which registers its disposal on
 * the scope it binds into.
 *
 * Ownership travels with construction, not with the type. A factory
 * that opens the transport hands the cache the operation that closes
 * it; a cache constructed around a transport the caller already holds
 * closes nothing, and dispose() is then a no-op.
 *
 * dispose() is idempotent and safe before the cache's first command: a
 * worker that never touched the cache opens nothing to close, and a
 * second call does nothing. Nothing reuses a cache it has disposed.
 */
interface DisposableCacheInterface
{
    public function dispose(): void;
}
