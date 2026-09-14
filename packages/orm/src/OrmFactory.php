<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use InvalidArgumentException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlTransaction;

/**
 * Request-neutral: the link and one runtime plan per entity, built once from
 * a MetadataRegistry. It holds no entity and no unit-of-work state, so it can
 * live for the whole process; open() gives each unit of work its own
 * EntityManager.
 */
final readonly class OrmFactory
{
    /**
     * @param array<class-string, EntityPlan<object>> $plans
     */
    private function __construct(
        private MysqlLink|PostgresLink $link,
        private array $plans,
    ) {}

    /**
     * @throws InvalidArgumentException for a transaction, which also carries
     *         a link's dialect marker: an EntityManager reads through the
     *         client and flushes in a transaction it begins there
     */
    public static function create(MysqlLink|PostgresLink $link, MetadataRegistry $metadata): self
    {
        if ($link instanceof SqlTransaction) {
            throw new InvalidArgumentException(
                'OrmFactory::create() was given a transaction (' . $link::class . '). An EntityManager reads through '
                . 'a client and flushes in a transaction it begins on that client: pass the client, not a transaction '
                . 'it began.',
            );
        }

        $plans = [];

        foreach ($metadata->toArray()['entities'] as $mapping) {
            $plans[$mapping['class']] = new EntityPlan($mapping);
        }

        return new self($link, $plans);
    }

    /**
     * A new EntityManager owned by the calling Fiber. Closing it leaves the
     * link open.
     */
    public function open(): EntityManager
    {
        return EntityManager::open($this->link, $this->plans);
    }
}
