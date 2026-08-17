<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Events\ActionEvent;
use App\Listeners\ActionEventListener;
use App\Services\SoketiPublisher;
use Kinetis\Events\Listener;
use PHPUnit\Framework\TestCase;
use Pusher\Pusher;
use ReflectionMethod;

/**
 * The one hop between a dispatched event and the browser. It is
 * discovered by attribute and by the type of its single parameter, so
 * both are part of the contract: change either and the dashboard goes
 * quiet with nothing failing anywhere.
 */
final class ActionEventListenerTest extends TestCase
{
    public function test_republishes_the_event_to_soketi(): void
    {
        $pusher = $this->createMock(Pusher::class);
        $pusher->expects(self::once())
            ->method('trigger')
            ->with(
                SoketiPublisher::CHANNEL,
                'action',
                ['stage' => 'socket', 'id' => 12, 'scenario' => 'queued'],
            );

        new ActionEventListener(new SoketiPublisher($pusher))
            ->onActionEvent(new ActionEvent('socket', 12, 'queued'));
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
