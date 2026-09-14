<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlTransaction;

/**
 * A scriptable transaction. Statements are recorded and answered as on the
 * spy links; commit(), rollback() and close() are recorded in $ends, in
 * call order. A hook runs inside commit() or rollback() before it returns,
 * where a driver waits on the server: throwing fails the call, and
 * suspending the Fiber holds it open.
 */
final class SpyMysqlTransaction implements MysqlTransaction
{
    use RecordsCalls;

    /** @var list<'commit'|'rollback'|'close'> */
    public array $ends = [];

    public ?Closure $onCommit = null;

    public ?Closure $onRollback = null;

    private bool $active = true;

    public function commit(): void
    {
        $this->ends[] = 'commit';
        $this->finish($this->onCommit);
    }

    public function rollback(): void
    {
        $this->ends[] = 'rollback';
        $this->finish($this->onRollback);
    }

    public function close(): void
    {
        $this->ends[] = 'close';
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isClosed(): bool
    {
        return !$this->active;
    }

    private function finish(?Closure $hook): void
    {
        try {
            if ($hook !== null) {
                $hook();
            }
        } finally {
            $this->active = false;
        }
    }
}
