<?php

declare(strict_types=1);

namespace Kinetis\Tests\Persistence;

use Kinetis\Persistence\Exception\PoolExhaustedException;
use Kinetis\Persistence\Pool;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PoolTest extends TestCase
{
    public function test_acquire_builds_a_new_connection_via_the_factory(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass());

        $connection = $pool->acquire();

        self::assertInstanceOf(stdClass::class, $connection);
        self::assertSame(1, $pool->size());
    }

    public function test_released_connections_are_reused_instead_of_rebuilt(): void
    {
        $built = 0;
        $pool = new Pool(static function () use (&$built): stdClass {
            $built++;

            return new stdClass();
        });

        $connection = $pool->acquire();
        $pool->release($connection);

        self::assertSame($connection, $pool->acquire());
        self::assertSame(1, $built);
    }

    public function test_throws_when_max_size_is_reached(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass(), maxSize: 2);

        $pool->acquire();
        $pool->acquire();

        $this->expectException(PoolExhaustedException::class);
        $pool->acquire();
    }

    public function test_unhealthy_released_connections_are_discarded_not_reused(): void
    {
        $built = 0;
        $pool = new Pool(
            factory: static function () use (&$built): stdClass {
                $built++;

                $connection = new stdClass();
                $connection->alive = true;

                return $connection;
            },
            isHealthy: static fn (stdClass $c): bool => $c->alive,
        );

        $connection = $pool->acquire();
        $connection->alive = false;
        $pool->release($connection);

        $fresh = $pool->acquire();

        self::assertNotSame($connection, $fresh);
        self::assertSame(2, $built);
    }

    public function test_discarding_an_unhealthy_connection_frees_capacity_for_a_new_one(): void
    {
        $pool = new Pool(
            factory: static function (): stdClass {
                $connection = new stdClass();
                $connection->alive = true;

                return $connection;
            },
            isHealthy: static fn (stdClass $c): bool => $c->alive,
            maxSize: 1,
        );

        $connection = $pool->acquire();
        $connection->alive = false;
        $pool->release($connection);

        // Without the discard decrementing size, this would throw
        // PoolExhaustedException even though nothing is actually in use.
        $fresh = $pool->acquire();

        self::assertNotSame($connection, $fresh);
    }
}
