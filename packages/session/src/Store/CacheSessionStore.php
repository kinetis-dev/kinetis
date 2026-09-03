<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Support\SessionExpiry;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * Sessions over any PSR-16 cache — which is how Redis-backed sessions
 * work with no Redis code here at all: with `REDIS_HOST`/`REDIS_URL`
 * configured, the `CacheInterface` binding kinetis/cache-redis already
 * provides carries these payloads, cluster mode and TLS included. The
 * backend's own TTL is the expiry mechanism.
 *
 * Refuses a NullSimpleCache outright: a session store that never stores
 * means every request arrives as a stranger — logins that silently
 * don't stick, CSRF tokens that never match. Failing at construction
 * names the misconfiguration; failing per-request would blame the
 * application.
 */
final readonly class CacheSessionStore implements SessionStoreInterface
{
    public function __construct(private CacheInterface $cache)
    {
        if ($cache instanceof NullSimpleCache) {
            throw new SessionException(
                'CacheSessionStore needs a real cache: the bound CacheInterface is NullSimpleCache, which never '
                . 'stores anything, so no session would ever survive its own request. Configure Redis '
                . '(REDIS_HOST/REDIS_URL with kinetis/cache-redis installed) or use another SESSION_DRIVER.',
            );
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $data = $this->cache->get(self::keyFor($id));

        /** @var ?array<string, mixed> */
        return \is_array($data) ? $data : null;
    }

    /**
     * PSR-16 allows `set()`/`delete()` to report failure via their own
     * documented boolean return rather than throwing — checked here
     * explicitly, since a silently-ignored `false` from `write()` would
     * have `SessionMiddleware` send a cookie for state that was never
     * actually persisted, and one from `destroy()` would leave the old
     * session live through a logout or `regenerate()` while the browser
     * cookie is expired/rotated regardless. A cache that throws instead
     * of returning `false` is left alone — its own exception is what
     * propagates, never replaced by a `SessionException` here.
     *
     * $lifetimeSeconds itself is validated via {@see SessionExpiry} —
     * this store never computes an absolute expiry timestamp (the
     * relative TTL goes straight to the cache backend, which computes
     * its own), but a non-positive lifetime must be rejected here the
     * same way the other stores reject it, rather than silently
     * deferring to whatever the backend happens to do with one.
     *
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        SessionExpiry::assertValidLifetime($lifetimeSeconds);

        if (!$this->cache->set(self::keyFor($id), $data, $lifetimeSeconds)) {
            throw new SessionException("Session data for \"{$id}\" could not be written to the cache.");
        }
    }

    #[\Override]
    public function destroy(string $id): void
    {
        if (!$this->cache->delete(self::keyFor($id))) {
            throw new SessionException("Session data for \"{$id}\" could not be deleted from the cache.");
        }
    }

    private static function keyFor(string $id): string
    {
        return 'session.' . $id;
    }
}
