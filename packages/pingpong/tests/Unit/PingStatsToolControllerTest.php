<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mcp\PingStatsToolController;
use App\Repositories\PingRepository;
use App\Tests\Fixtures\CannedMysqlLink;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The MCP tool an agent calls. Its only real logic is turning counts
 * into percentages, and the case worth pinning is the empty one: an
 * application with no pings yet must report zeroes rather than divide
 * by its own total.
 */
final class PingStatsToolControllerTest extends TestCase
{
    /**
     * PingRepository is final and cannot be doubled, so the count the
     * tool sees is set by what the connection underneath returns — every
     * count() reads the same canned aggregate.
     */
    private function controllerCounting(int $perQuery): PingStatsToolController
    {
        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, new EventListenerRegistry());
        $app->boot();
        $scope = $app->createRequestScope();

        $events = new EventDispatcher(
            $scope,
            $scope->get(EventListenerRegistry::class),
            $scope->get(ListenerInvokerInterface::class),
        );

        return new PingStatsToolController(
            new PingRepository(new CannedMysqlLink([['aggregate' => $perQuery]]), $events),
        );
    }

    public function test_reports_each_scenario_as_a_share_of_the_total(): void
    {
        $breakdown = $this->controllerCounting(6)->pingScenarioBreakdown();

        self::assertSame(6, $breakdown->total);
        self::assertSame(6, $breakdown->byScenario['direct']->count);
        // Every scenario reads the same canned count, so each is the
        // whole of the total.
        self::assertSame(100.0, $breakdown->byScenario['direct']->percentage);
        self::assertSame(100.0, $breakdown->byScenario['queued']->percentage);
    }

    public function test_an_empty_application_reports_zero_rather_than_dividing_by_it(): void
    {
        $breakdown = $this->controllerCounting(0)->pingScenarioBreakdown();

        self::assertSame(0, $breakdown->total);
        self::assertSame(0.0, $breakdown->byScenario['direct']->percentage);
    }

}
