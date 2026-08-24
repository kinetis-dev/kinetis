<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Events\ActionEvent;
use App\Listeners\ActionEventListener;
use Kinetis\Broadcasting\Broadcaster;
use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Events\Listener;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The one hop between a dispatched event and the browser. It is
 * discovered by attribute and by the type of its single parameter, so
 * both are part of the contract: change either and the dashboard goes
 * quiet with nothing failing anywhere.
 */
final class ActionEventListenerTest extends TestCase
{
    public function test_republishes_the_event_to_the_public_channel(): void
    {
        $driver = $this->createMock(BroadcasterInterface::class);
        $driver->expects(self::once())
            ->method('broadcast')
            ->with(
                ActionEventListener::PUBLIC_CHANNEL,
                'action',
                ['stage' => 'app', 'id' => 12, 'scenario' => 'queued'],
            );

        new ActionEventListener(new Broadcaster($driver))
            ->onActionEvent(new ActionEvent('app', 12, 'queued'));
    }

    /**
     * A ponged ping additionally notifies the private channel — the one
     * stage that means "this visitor's ping actually completed", which
     * is also the demo for kinetis/broadcasting's private channels.
     */
    public function test_a_socket_stage_also_notifies_the_private_channel(): void
    {
        $calls = [];
        $driver = $this->createMock(BroadcasterInterface::class);
        $driver->expects(self::exactly(2))
            ->method('broadcast')
            ->willReturnCallback(function (string $channel, string $event, array $payload) use (&$calls): void {
                $calls[] = [$channel, $event, $payload];
            });

        new ActionEventListener(new Broadcaster($driver))
            ->onActionEvent(new ActionEvent('socket', 12, 'queued'));

        self::assertSame(
            [ActionEventListener::PUBLIC_CHANNEL, 'action', ['stage' => 'socket', 'id' => 12, 'scenario' => 'queued']],
            $calls[0],
        );
        self::assertSame(
            [ActionEventListener::PRIVATE_CHANNEL, 'pong.notified', ['id' => 12, 'scenario' => 'queued']],
            $calls[1],
        );
    }

    /**
     * The browser flashes a diagram node per stage; a stage with no row
     * yet, or no scenario, still has to arrive rather than be dropped.
     */
    public function test_a_stage_without_an_id_or_scenario_is_still_published(): void
    {
        $driver = $this->createMock(BroadcasterInterface::class);
        $driver->expects(self::once())
            ->method('broadcast')
            ->with(ActionEventListener::PUBLIC_CHANNEL, 'action', ['stage' => 'app', 'id' => null, 'scenario' => null]);

        new ActionEventListener(new Broadcaster($driver))->onActionEvent(new ActionEvent('app'));
    }

    public function test_is_discoverable_as_a_listener_for_the_action_event(): void
    {
        $method = new ReflectionMethod(ActionEventListener::class, 'onActionEvent');

        self::assertCount(1, $method->getAttributes(Listener::class));

        $parameters = $method->getParameters();
        self::assertCount(1, $parameters, 'a listener takes exactly the event it listens for');
        self::assertSame(ActionEvent::class, (string) $parameters[0]->getType());
    }
}
