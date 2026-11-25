<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Fiber;
use InvalidArgumentException;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CommitNotAcknowledgedException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\ReportsJsonDepth;
use Kinetis\Persistence\Contract\SqlTransaction;
use Throwable;
use WeakMap;

/**
 * Request-neutral: the link and one runtime plan per entity of one
 * connection, built once from a MetadataRegistry. It holds no entity and no unit-of-work state, so it can
 * live for the whole process; open() gives each unit of work its own
 * EntityManager, and transaction() gives it one bound to a transaction. The
 * only state it changes is which Fibers are inside transaction(), cleared
 * when each call returns or throws.
 */
final class OrmFactory
{
    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, true> Fibers inside transaction() */
    private readonly WeakMap $sessions;

    /** Whether the main context is inside transaction(). */
    private bool $mainSession = false;

    /**
     * @param array<class-string, EntityPlan<object>> $plans
     * @param positive-int $jsonDepth
     */
    private function __construct(
        private readonly MysqlLink|PostgresLink $link,
        private readonly array $plans,
        private readonly int $jsonDepth,
    ) {
        $this->sessions = new WeakMap();
    }

    /**
     * Maps the entities of $metadata that live on $connection, over $link:
     * the client of that connection. A connection no entity names gives a
     * factory that maps none.
     *
     * The link's JSON depth is read once here, which opens a connection on
     * a MySQL-family link that has none open yet.
     *
     * @throws InvalidArgumentException for a transaction, which also carries
     *         a link's dialect marker: an EntityManager reads through the
     *         client, and every transaction it writes in begins there; and
     *         for a link that does not report its server's JSON depth
     */
    public static function create(
        MysqlLink|PostgresLink $link,
        MetadataRegistry $metadata,
        string $connection = 'default',
    ): self {
        if ($link instanceof SqlTransaction) {
            throw new InvalidArgumentException(
                'OrmFactory::create() was given a transaction (' . $link::class . '). An EntityManager reads through '
                . 'a client, and every transaction it writes in begins on that client: pass the client, not a '
                . 'transaction it began.',
            );
        }

        if (!$link instanceof ReportsJsonDepth) {
            throw new InvalidArgumentException(
                'OrmFactory::create() was given a link that does not report the JSON depth its server stores ('
                . $link::class . '). Pass a client that implements ' . ReportsJsonDepth::class . ', as every '
                . 'Kinetis\\Persistence\\Driver client does.',
            );
        }

        $jsonDepth = $link->jsonDepth();
        $plans = [];

        foreach ($metadata->toArray()['entities'] as $mapping) {
            if ($mapping['connection'] === $connection) {
                $plans[$mapping['class']] = new EntityPlan($mapping, $jsonDepth);
            }
        }

        return new self($link, $plans, $jsonDepth);
    }

    /**
     * A new EntityManager owned by the calling Fiber. Closing it leaves the
     * link open.
     */
    public function open(): EntityManager
    {
        return EntityManager::open($this->link, $this->plans, $this->jsonDepth);
    }

    /**
     * The levels of nested arrays and objects a JSON property of this
     * connection holds at most: what its server stores, as the link
     * reported it when the factory was created. A deeper value is refused
     * before SQL.
     *
     * @return positive-int
     */
    public function jsonDepth(): int
    {
        return $this->jsonDepth;
    }

    /**
     * Begins a transaction on the client, passes $callback an EntityManager
     * bound to it and a SessionTransaction over it, and commits once the
     * callback returns. The manager is closed and its entities detached
     * before this returns or throws. The package README's "Transaction
     * sessions" states the contract and every failure.
     *
     * @template TResult
     * @param callable(EntityManager, SessionTransaction): TResult $callback
     * @return TResult
     * @throws InvalidEntityStateException when the calling Fiber is already inside transaction() on this factory
     * @throws ClosedEntityManagerException when the callback closed the manager
     * @throws RollbackFailedException
     * @throws CommitNotAcknowledgedException
     */
    public function transaction(callable $callback): mixed
    {
        /** @var Fiber<mixed, mixed, mixed, mixed>|null $fiber null is the main context */
        $fiber = Fiber::getCurrent();

        if ($fiber === null ? $this->mainSession : isset($this->sessions[$fiber])) {
            throw InvalidEntityStateException::nestedTransaction();
        }

        if ($fiber === null) {
            $this->mainSession = true;
        } else {
            $this->sessions->offsetSet($fiber, true);
        }

        // Only the re-entry mark is cleared here. Every end of the
        // transaction is an explicit phase of session(), so a Fiber destroyed
        // while suspended unwinds without sending anything, and the dropped
        // transaction records its own unacknowledged outcome.
        try {
            return $this->session($callback);
        } finally {
            if ($fiber === null) {
                $this->mainSession = false;
            } else {
                $this->sessions->offsetUnset($fiber);
            }
        }
    }

    /**
     * @template TResult
     * @param callable(EntityManager, SessionTransaction): TResult $callback
     * @return TResult
     */
    private function session(callable $callback): mixed
    {
        $transaction = $this->link->beginTransaction();
        $manager = EntityManager::bind($transaction, $this->plans, $this->jsonDepth);

        try {
            $result = $callback($manager, SessionTransaction::of($transaction));
        } catch (Throwable $callbackFailure) {
            // A recorded ORM failure stays primary: the callback may have
            // caught it before throwing an exception of its own.
            self::rollback($manager, $transaction, $manager->failure() ?? $callbackFailure);
        }

        $failure = $manager->failure();

        if ($failure !== null) {
            self::rollback($manager, $transaction, $failure);
        }

        // close() has already ended the transaction, so nothing is committed.
        if ($manager->isClosed()) {
            throw ClosedEntityManagerException::manager();
        }

        try {
            $transaction->commit();
        } catch (Throwable $commitFailure) {
            $manager->finish(false);

            throw new CommitNotAcknowledgedException($commitFailure);
        }

        $manager->finish(true);

        return $result;
    }

    /**
     * No COMMIT was sent. rollback() ends an active transaction and returns
     * on one that already ended.
     *
     * @throws RollbackFailedException
     */
    private static function rollback(EntityManager $manager, SqlTransaction $transaction, Throwable $failure): never
    {
        try {
            $transaction->rollback();
        } catch (Throwable $rollbackFailure) {
            $manager->finish(false);

            throw new RollbackFailedException($failure, $rollbackFailure);
        }

        $manager->finish(false);

        throw $failure;
    }
}
