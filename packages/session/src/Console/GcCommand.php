<?php

declare(strict_types=1);

namespace Kinetis\Session\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Output;
use Kinetis\Container\RequestScope;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;

/**
 * Deletes expired sessions from whichever store SESSION_DRIVER bound,
 * discovered via this package's extra.kinetis scan root. Schedule it
 * with whatever the deployment already uses (cron, a Kubernetes
 * CronJob, ...) — expired sessions in the `file` and `sql` drivers stay
 * in storage until this runs; the `redis` driver needs no collection at
 * all, since the key's own TTL expires it.
 */
final readonly class GcCommand
{
    public function __construct(
        private RequestScope $scope,
        private Output $output,
    ) {}

    #[Command('session:gc', description: 'Deletes expired sessions from the configured session store.')]
    public function run(): int
    {
        if (!$this->scope->has(SessionStoreInterface::class)) {
            $this->output->error('No session store is bound — set SESSION_DRIVER (file, redis, or sql).');

            return 1;
        }

        $store = $this->scope->get(SessionStoreInterface::class);

        if (!$store instanceof GarbageCollectableStoreInterface) {
            $this->output->line("Nothing to collect: this session store's backend expires entries on its own.");

            return 0;
        }

        $removed = $store->gc();
        $this->output->line("Removed {$removed} expired session(s).");

        return 0;
    }
}
