<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\Persistence\TransactionGuard;
use Kinetis\Queue\Job;

/**
 * Deliberately begins a transaction and never commits or rolls it back,
 * then returns normally — proving the cleanup the bridge registers on the
 * job's own RequestScope, when the job resolves its guard, is what
 * actually closes it, not the job itself. The HTTP equivalent of this
 * fixture is DanglingTransactionController.
 */
final readonly class DanglingTransactionJob implements Job
{
    public function handle(TransactionGuard $guard): void
    {
        $link = new FakeSqlLink();
        $guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;
    }
}
