<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use InvalidArgumentException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;

/**
 * Request-neutral: one OrmFactory per connection, each over that
 * connection's link and the entities of one MetadataRegistry that live on
 * it. Like each factory it holds, it keeps no entity and no unit-of-work
 * state, so it can live for the whole process; EntityManagerRegistry
 * gives each unit of work its managers.
 */
final readonly class OrmFactoryRegistry
{
    /**
     * @param array<string, OrmFactory> $factories keyed by connection
     */
    private function __construct(
        private MetadataRegistry $metadata,
        private array $factories,
    ) {}

    /**
     * Builds a factory for every connection in $links. Every connection
     * an entity of $metadata names needs its link; a link for a connection
     * no entity names gives a factory that maps none.
     *
     * @param array<string, MysqlLink|PostgresLink> $links each connection's client, keyed by the connection's name
     * @throws InvalidArgumentException when a connection of $metadata has no link, or a link is a transaction
     */
    public static function create(array $links, MetadataRegistry $metadata): self
    {
        foreach ($metadata->connections() as $connection) {
            if (!isset($links[$connection])) {
                throw new InvalidArgumentException(
                    "The entities on the \"{$connection}\" connection need its link, and none was given. Key that "
                    . "connection's MysqlLink or PostgresLink by \"{$connection}\".",
                );
            }
        }

        $factories = [];

        foreach ($links as $connection => $link) {
            $factories[$connection] = OrmFactory::create($link, $metadata, (string) $connection);
        }

        return new self($metadata, $factories);
    }

    /**
     * @throws InvalidArgumentException when no link was given for $connection
     */
    public function factory(string $connection): OrmFactory
    {
        return $this->factories[$connection] ?? throw new InvalidArgumentException(
            "This OrmFactoryRegistry was given no link for the \"{$connection}\" connection.",
        );
    }

    /**
     * The factory of the connection $class lives on.
     *
     * @param class-string $class
     * @throws MappingException when $class is not an entity in the metadata
     */
    public function factoryFor(string $class): OrmFactory
    {
        return $this->factory($this->metadata->connectionFor($class));
    }
}
