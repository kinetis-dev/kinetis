<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Queue\PongJob;
use App\Repositories\PingRepository;
use App\Tests\Fixtures\CannedMysqlLink;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\Job;
use Kinetis\Queue\JobSerializer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The queued half of the ping-pong scenario. Two things are worth
 * pinning: that running it marks the ping ponged, and that it survives
 * the round trip through the queue at all — a job whose constructor
 * arguments cannot be reconstructed is only discovered when a worker
 * tries to run it, which is somewhere else entirely.
 */
final class PongJobTest extends TestCase
{
    public function test_running_it_marks_the_ping_ponged(): void
    {
        $link = new CannedMysqlLink();
        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, new EventListenerRegistry());
        $app->boot();
        $scope = $app->createRequestScope();

        $events = new EventDispatcher(
            $scope,
            $scope->get(EventListenerRegistry::class),
            $scope->get(ListenerInvokerInterface::class),
        );

        new PongJob(7)->handle(new PingRepository($link, $events), $events);

        self::assertCount(1, $link->statements);
        [$sql, $params] = $link->statements[0];
        self::assertStringContainsString('UPDATE', $sql);
        self::assertContains('ponged', $params);
    }

    /**
     * JobSerializer reads each constructor parameter off a same-named
     * property. A job that computes its arguments into differently-named
     * properties cannot be reconstructed, and the failure surfaces at
     * push time — this proves it round-trips.
     */
    public function test_survives_the_queue_round_trip(): void
    {
        $serialized = JobSerializer::serialize(new PongJob(99));
        $restored = JobSerializer::deserialize($serialized['class'], $serialized['args']);

        self::assertSame(PongJob::class, $serialized['class']);
        self::assertInstanceOf(PongJob::class, $restored);
        self::assertSame(99, $restored->id);
    }

    public function test_is_a_queue_job(): void
    {
        self::assertTrue(new ReflectionClass(PongJob::class)->implementsInterface(Job::class));
    }
}
