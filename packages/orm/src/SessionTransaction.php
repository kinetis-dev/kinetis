<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;

/**
 * The transaction of one OrmFactory::transaction() session, passed as the
 * callback's second argument so code that takes a SqlTransaction, such as
 * kinetis/queue-sql's SqlQueue::pushOn(), runs its statements inside the
 * session.
 *
 * query() and execute() run on the session's transaction, and isActive()
 * and isClosed() report it; the wrapped transaction refuses another Fiber
 * and a statement after the session ended. commit(), rollback(), close()
 * and beginTransaction() throw without reaching it: the session alone ends
 * its transaction, and sessions do not nest.
 *
 * It carries no dialect marker — it is neither a MysqlLink nor a
 * PostgresLink — so kinetis/query-builder cannot target it.
 * EntityManager::builder() is the query builder on the session's
 * transaction.
 *
 * @implements SqlTransaction<never>
 */
final readonly class SessionTransaction implements SqlTransaction
{
    private function __construct(private MysqlTransaction|PostgresTransaction $transaction)
    {
    }

    /**
     * @internal OrmFactory::transaction() wraps the transaction each
     *           session begins.
     */
    public static function of(MysqlTransaction|PostgresTransaction $transaction): self
    {
        return new self($transaction);
    }

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->transaction->query($sql);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->transaction->execute($sql, $params);
    }

    #[\Override]
    public function isActive(): bool
    {
        return $this->transaction->isActive();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->transaction->isClosed();
    }

    /** @throws InvalidEntityStateException */
    #[\Override]
    public function commit(): never
    {
        throw InvalidEntityStateException::sessionOwnsTransaction('commit');
    }

    /** @throws InvalidEntityStateException */
    #[\Override]
    public function rollback(): never
    {
        throw InvalidEntityStateException::sessionOwnsTransaction('rollback');
    }

    /** @throws InvalidEntityStateException */
    #[\Override]
    public function close(): never
    {
        throw InvalidEntityStateException::sessionOwnsTransaction('close');
    }

    /** @throws InvalidEntityStateException */
    #[\Override]
    public function beginTransaction(): never
    {
        throw InvalidEntityStateException::sessionOwnsTransaction('beginTransaction');
    }
}
