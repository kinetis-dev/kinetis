<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use RuntimeException;
use Throwable;

/**
 * Answers each successive execute() call with the next entry of a fixed
 * script — a SqlResult to return, or a Throwable to throw — proving
 * SqlSessionStore's own retry/verify sequencing deterministically rather
 * than against a real database's actual duplicate-key/race behavior
 * (which the environment-gated SqlSessionStoreIntegrationTest already
 * covers for the one thing a fake can't prove: how a real server counts
 * affected rows). query() is never called by SqlSessionStore, so it
 * stays unreachable-and-throwing, the same idiom RecordingSqlLink
 * already establishes elsewhere in this project.
 *
 * @internal test fixture only
 */
final class ScriptedSqlLink implements SqlLink
{
    /** @var list<array{string, array<int|string, mixed>}> */
    public array $executed = [];

    /**
     * @param list<SqlResult|Throwable> $script
     */
    public function __construct(private array $script)
    {
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        throw new RuntimeException('SqlSessionStore never calls query().');
    }

    /**
     * @param array<int|string, mixed> $params
     */
    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->executed[] = [$sql, $params];

        if ($this->script === []) {
            throw new RuntimeException("ScriptedSqlLink ran out of scripted responses at execute(\"{$sql}\").");
        }

        $next = \array_shift($this->script);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new RuntimeException('SqlSessionStore never calls beginTransaction().');
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function isClosed(): bool
    {
        return false;
    }
}
