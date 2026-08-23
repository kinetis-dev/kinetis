<?php

declare(strict_types=1);

namespace Kinetis\Session\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Container\RequestScope;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;

/**
 * Deletes expired sessions from whichever store SESSION_DRIVER bound,
 * discovered via this package's extra.kinetis scan root. Schedule it
 * with whatever the deployment already uses (cron, a Kubernetes
 * CronJob, ...) — expired sessions in the `file` and `sql` drivers stay
 * in storage until this runs; the `cache` driver needs no collection at
 * all, since its backend (Redis TTL, for one) expires entries itself.
 */
final readonly class GcCommand
{
    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *                         resource type — injectable for testability
     */
    public function __construct(
        private RequestScope $scope,
        private mixed $output = STDOUT,
    ) {}

    #[Command('session:gc', description: 'Deletes expired sessions from the configured session store.')]
    public function run(): int
    {
        if (!$this->scope->has(SessionStoreInterface::class)) {
            \fwrite($this->output, "No session store is bound — set SESSION_DRIVER (file, cache, or sql).\n");

            return 1;
        }

        $store = $this->scope->get(SessionStoreInterface::class);

        if (!$store instanceof GarbageCollectableStoreInterface) {
            \fwrite($this->output, "Nothing to collect: this session store's backend expires entries on its own.\n");

            return 0;
        }

        $removed = $store->gc();
        \fwrite($this->output, "Removed {$removed} expired session(s).\n");

        return 0;
    }
}
