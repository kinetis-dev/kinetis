<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use PHPUnit\Framework\Attributes\Before;

/**
 * Stands in for kinetis/persistence's DatabaseTransactions, which reads
 * $this->app from its own #[Before] hook and so depends on the base
 * class's hook having already run.
 */
trait RecordsHookOrder
{
    protected bool $appWasBootedBeforeTheTraitHookRan = false;

    #[Before]
    protected function recordHookOrder(): void
    {
        $this->appWasBootedBeforeTheTraitHookRan = isset($this->app) && $this->app->isBooted();
    }
}
