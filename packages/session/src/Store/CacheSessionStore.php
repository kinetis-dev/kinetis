<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\SessionStoreInterface;
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
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->cache->set(self::keyFor($id), $data, $lifetimeSeconds);
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $this->cache->delete(self::keyFor($id));
    }

    private static function keyFor(string $id): string
    {
        return 'session.' . $id;
    }
}
