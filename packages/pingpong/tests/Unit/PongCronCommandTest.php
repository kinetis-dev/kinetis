<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\PongCronCommand;
use App\Repositories\PingRepository;
use App\Tests\Fixtures\CannedMysqlLink;
use Kinetis\Console\Attributes\Command;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The scheduled scenario: it creates and pongs its own ping with no
 * request involved. Its exit code is what a scheduler reads to decide
 * whether to alert, so that is worth asserting rather than assuming.
 */
final class PongCronCommandTest extends TestCase
{
    public function test_creates_a_ping_and_pongs_it(): void
    {
        $link = new CannedMysqlLink(insertId: 3);
        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, new EventListenerRegistry());
        $app->boot();
        $scope = $app->createRequestScope();

        $events = new EventDispatcher(
            $scope,
            $scope->get(EventListenerRegistry::class),
            $scope->get(ListenerInvokerInterface::class),
        );

        $exit = new PongCronCommand(new PingRepository($link, $events), $events)->run();

        self::assertSame(0, $exit);
        self::assertCount(2, $link->statements, 'expected one insert and one update');
        self::assertStringContainsString('INSERT INTO', $link->statements[0][0]);
        self::assertContains('cron', $link->statements[0][1]);
        self::assertStringContainsString('UPDATE', $link->statements[1][0]);
        self::assertContains('ponged', $link->statements[1][1]);
    }

    /**
     * Discovery finds it by attribute, so the name a scheduler invokes is
     * part of the contract rather than an implementation detail.
     */
    public function test_is_registered_under_the_name_the_scheduler_calls(): void
    {
        $attributes = new ReflectionMethod(PongCronCommand::class, 'run')->getAttributes(Command::class);

        self::assertCount(1, $attributes);
        self::assertSame('pings:pong-cron', $attributes[0]->newInstance()->name);
    }
}
