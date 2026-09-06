<?php

declare(strict_types=1);

namespace Kinetis\Redis\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Redis\Protocol\RedisResponse;

/**
 * One command waiting for its reply. Settles exactly once, whether by a
 * reply, by the connection being discarded, or by the operation budget
 * expiring, so no waiter is ever resumed twice and none is left hanging.
 *
 * @internal
 */
final class Frame
{
    private readonly DeferredFuture $deferred;

    private bool $settled = false;

    public function __construct()
    {
        $this->deferred = new DeferredFuture();
        // The awaiter walks away when its own budget expires; the
        // failure recorded here afterwards has no one left to read it.
        $this->deferred->getFuture()->ignore();
    }

    /** @return Future<RedisResponse> */
    public function future(): Future
    {
        return $this->deferred->getFuture();
    }

    public function complete(RedisResponse $response): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->deferred->complete($response);
        }
    }

    public function fail(\Throwable $reason): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->deferred->error($reason);
        }
    }
}
