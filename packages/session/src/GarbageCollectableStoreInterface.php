<?php

declare(strict_types=1);

namespace Kinetis\Session;

/**
 * A session store whose expired entries stay in storage until something
 * deletes them. Store\FileSessionStore and Store\SqlSessionStore
 * implement it; Store\CacheSessionStore does not, because a PSR-16
 * backend expires its entries on its own. The `session:gc` command
 * calls gc() on whichever store is bound — schedule it with whatever
 * the deployment already uses (cron, a Kubernetes CronJob, ...);
 * nothing runs it implicitly.
 */
interface GarbageCollectableStoreInterface
{
    /** Deletes every expired session; returns how many were removed. */
    public function gc(): int;
}
