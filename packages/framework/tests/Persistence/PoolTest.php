<?php

declare(strict_types=1);

namespace Kinetis\Tests\Persistence;

use Kinetis\Persistence\Exception\InvalidPoolConfigurationException;
use Kinetis\Persistence\Exception\InvalidPoolReleaseException;
use Kinetis\Persistence\Exception\PoolDisposalFailedException;
use Kinetis\Persistence\Exception\PoolExhaustedException;
use Kinetis\Persistence\Pool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    public function test_a_failed_construction_does_not_change_size_or_consume_capacity(): void
    {
        $attempts = 0;
        $pool = new Pool(
            factory: static function () use (&$attempts): stdClass {
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('transient connection failure');
                }

                return new stdClass();
            },
            maxSize: 1,
        );

        try {
            $pool->acquire();
            self::fail('Expected the first factory call to throw.');
        } catch (RuntimeException $e) {
            self::assertSame('transient connection failure', $e->getMessage());
        }

        self::assertSame(0, $pool->size());

        $connection = $pool->acquire();

        self::assertInstanceOf(stdClass::class, $connection);
        self::assertSame(1, $pool->size());
        self::assertSame(2, $attempts);
    }

    public function test_repeated_failures_up_to_max_size_never_cause_false_exhaustion(): void
    {
        $pool = new Pool(
            factory: static function (): stdClass {
                throw new RuntimeException('always fails');
            },
            maxSize: 2,
        );

        for ($i = 0; $i < 5; $i++) {
            try {
                $pool->acquire();
                self::fail('Expected the factory to throw.');
            } catch (RuntimeException $e) {
                self::assertSame('always fails', $e->getMessage());
            }

            // A failed attempt must never count toward size, so this can
            // never turn into PoolExhaustedException no matter how many
            // times it's retried past maxSize.
            self::assertSame(0, $pool->size());
        }
    }

    public function test_max_size_below_one_is_rejected_at_construction(): void
    {
        $this->expectException(InvalidPoolConfigurationException::class);

        new Pool(static fn (): stdClass => new stdClass(), maxSize: 0);
    }

    public function test_a_negative_max_size_is_rejected_at_construction(): void
    {
        $this->expectException(InvalidPoolConfigurationException::class);

        new Pool(static fn (): stdClass => new stdClass(), maxSize: -1);
    }

    // --- Identity/state -----------------------------------------------

    public function test_releasing_a_connection_never_created_by_this_pool_is_rejected(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass());
        $foreign = new stdClass();

        $this->expectException(InvalidPoolReleaseException::class);
        $pool->release($foreign);
    }

    public function test_releasing_a_connection_created_by_a_different_pool_is_rejected(): void
    {
        $factory = static fn (): stdClass => new stdClass();
        $poolA = new Pool($factory);
        $poolB = new Pool($factory);

        $connection = $poolA->acquire();

        $this->expectException(InvalidPoolReleaseException::class);
        $poolB->release($connection);
    }

    public function test_releasing_the_same_connection_twice_is_rejected_on_the_second_call(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass());
        $connection = $pool->acquire();

        $pool->release($connection);

        $this->expectException(InvalidPoolReleaseException::class);
        $pool->release($connection);
    }

    public function test_a_connection_can_be_released_again_after_being_reacquired(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass());
        $connection = $pool->acquire();

        $pool->release($connection);
        self::assertSame($connection, $pool->acquire());

        // Valid the second time around, since it's genuinely checked out
        // again — this is what proves release()'s rejection is a real
        // state check, not a one-shot "this object was ever released"
        // memory that would wrongly refuse a legitimate second release.
        $pool->release($connection);
        self::assertSame($connection, $pool->acquire());
    }

    public function test_two_simultaneous_checkouts_are_never_the_same_connection(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass(), maxSize: 2);

        $first = $pool->acquire();
        $second = $pool->acquire();

        self::assertNotSame($first, $second);
    }

    public function test_a_rejected_foreign_release_leaves_pool_state_unchanged(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass(), maxSize: 1);
        $connection = $pool->acquire();

        try {
            $pool->release(new stdClass());
            self::fail('Expected the foreign release to be rejected.');
        } catch (InvalidPoolReleaseException) {
            // Expected.
        }

        // Size is unaffected by the rejected call, and the pool still
        // correctly considers itself exhausted — a hostile release must
        // never be able to manufacture idle capacity that doesn't exist.
        self::assertSame(1, $pool->size());
        $this->expectException(PoolExhaustedException::class);
        $pool->acquire();

        // The real checked-out connection is untouched by the rejected
        // call and remains genuinely releasable afterward.
        $pool->release($connection);
    }

    public function test_a_rejected_double_release_leaves_pool_state_unchanged(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass(), maxSize: 1);
        $connection = $pool->acquire();
        $pool->release($connection);

        try {
            $pool->release($connection);
            self::fail('Expected the double release to be rejected.');
        } catch (InvalidPoolReleaseException) {
            // Expected.
        }

        // Exactly one idle member, not two — the rejected second release
        // never got the chance to duplicate the entry.
        self::assertSame($connection, $pool->acquire());
        $this->expectException(PoolExhaustedException::class);
        $pool->acquire();
    }

    public function test_max_size_is_enforced_despite_hostile_releases(): void
    {
        $pool = new Pool(static fn (): stdClass => new stdClass(), maxSize: 1);
        $pool->acquire();

        // A foreign release and a release of an untracked, never-acquired
        // object are both real attempts to manufacture idle capacity;
        // neither may succeed.
        foreach ([new stdClass(), new stdClass()] as $hostile) {
            try {
                $pool->release($hostile);
                self::fail('Expected the hostile release to be rejected.');
            } catch (InvalidPoolReleaseException) {
                // Expected.
            }
        }

        $this->expectException(PoolExhaustedException::class);
        $pool->acquire();
    }

    // --- Health/disposal matrix -----------------------------------------

    public function test_a_healthy_connection_is_returned_without_being_disposed(): void
    {
        $disposed = [];
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static fn (): bool => true,
            onDiscard: static function (stdClass $c) use (&$disposed): void {
                $disposed[] = $c;
            },
        );

        $connection = $pool->acquire();
        $pool->release($connection);
        $reused = $pool->acquire();

        self::assertSame($connection, $reused);
        self::assertSame([], $disposed);
    }

    public function test_a_false_health_check_disposes_the_member_exactly_once_and_recovers_capacity(): void
    {
        $disposed = [];
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static fn (): bool => false,
            maxSize: 1,
            onDiscard: static function (stdClass $c) use (&$disposed): void {
                $disposed[] = $c;
            },
        );

        $connection = $pool->acquire();
        $pool->release($connection);

        $fresh = $pool->acquire();

        self::assertNotSame($connection, $fresh);
        self::assertSame([$connection], $disposed);
        self::assertSame(1, $pool->size());
    }

    public function test_a_false_health_check_whose_disposal_throws_reports_a_pool_domain_failure(): void
    {
        $disposeCalls = 0;
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static fn (): bool => false,
            maxSize: 1,
            onDiscard: static function () use (&$disposeCalls): void {
                $disposeCalls++;

                throw new RuntimeException('disposal failed');
            },
        );

        $connection = $pool->acquire();
        $pool->release($connection);

        try {
            $pool->acquire();
            self::fail('Expected acquire() to surface the disposal failure.');
        } catch (PoolDisposalFailedException $e) {
            self::assertNull($e->healthCheckFailure());
            self::assertSame('disposal failed', $e->disposalFailure()->getMessage());
        }

        self::assertSame(1, $disposeCalls);
        // Accounting stays correct even though disposal itself threw —
        // the slot is free, so this does not throw PoolExhaustedException.
        self::assertSame(0, $pool->size());
        $pool->acquire();
        self::assertSame(1, $pool->size());
    }

    public function test_a_health_check_that_throws_rethrows_the_same_exception_when_disposal_succeeds(): void
    {
        $disposed = [];
        $healthCheckFailure = new RuntimeException('health check exploded');
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static function () use ($healthCheckFailure): never {
                throw $healthCheckFailure;
            },
            maxSize: 1,
            onDiscard: static function (stdClass $c) use (&$disposed): void {
                $disposed[] = $c;
            },
        );

        $connection = $pool->acquire();
        $pool->release($connection);

        try {
            $pool->acquire();
            self::fail('Expected the health check to throw.');
        } catch (RuntimeException $e) {
            // The exact same exception instance — never wrapped, never
            // replaced — since disposal itself succeeded.
            self::assertSame($healthCheckFailure, $e);
        }

        self::assertSame([$connection], $disposed);
        self::assertSame(0, $pool->size());
        $pool->acquire();
        self::assertSame(1, $pool->size());
    }

    public function test_a_health_check_that_throws_and_a_disposal_that_also_throws_preserves_both(): void
    {
        $healthCheckFailure = new RuntimeException('health check exploded');
        $disposalFailure = new RuntimeException('disposal also failed');
        $disposeCalls = 0;
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static function () use ($healthCheckFailure): never {
                throw $healthCheckFailure;
            },
            maxSize: 1,
            onDiscard: static function () use (&$disposeCalls, $disposalFailure): void {
                $disposeCalls++;

                throw $disposalFailure;
            },
        );

        $connection = $pool->acquire();
        $pool->release($connection);

        try {
            $pool->acquire();
            self::fail('Expected acquire() to surface a pool-domain failure.');
        } catch (PoolDisposalFailedException $e) {
            // The health-check failure's exact identity is preserved,
            // never replaced by the disposal failure — reachable both
            // through the dedicated accessor and as getPrevious(), which
            // is what makes it "chained/loggable cleanup context" for a
            // generic exception-chain logger too.
            self::assertSame($healthCheckFailure, $e->healthCheckFailure());
            self::assertSame($healthCheckFailure, $e->getPrevious());
            self::assertSame($disposalFailure, $e->disposalFailure());
        }

        self::assertSame(1, $disposeCalls);
        self::assertSame(0, $pool->size());
        $pool->acquire();
        self::assertSame(1, $pool->size());
    }

    public function test_disposal_is_never_invoked_for_a_connection_still_checked_out(): void
    {
        $disposed = [];
        $pool = new Pool(
            factory: static fn (): stdClass => new stdClass(),
            isHealthy: static fn (): bool => false,
            onDiscard: static function (stdClass $c) use (&$disposed): void {
                $disposed[] = $c;
            },
        );

        // Never released — still checked out, so acquire() never revisits
        // it and onDiscard is never called for it.
        $pool->acquire();

        self::assertSame([], $disposed);
    }
}
