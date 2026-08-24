<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\Broadcaster;
use Kinetis\Broadcasting\Tests\Fixtures\OrderUpdated;
use Kinetis\Broadcasting\Tests\Fixtures\RecordingBroadcaster;
use PHPUnit\Framework\TestCase;

final class BroadcasterTest extends TestCase
{
    public function test_broadcast_forwards_the_raw_call_to_the_driver(): void
    {
        $driver = new RecordingBroadcaster();
        $broadcaster = new Broadcaster($driver);

        $broadcaster->broadcast('orders', 'order.updated', ['status' => 'shipped']);

        self::assertSame(
            [['channel' => 'orders', 'event' => 'order.updated', 'payload' => ['status' => 'shipped']]],
            $driver->calls,
        );
    }

    public function test_event_broadcasts_on_every_channel_the_event_names(): void
    {
        $driver = new RecordingBroadcaster();
        $broadcaster = new Broadcaster($driver);

        $broadcaster->event(new OrderUpdated('42', 'shipped'));

        self::assertCount(2, $driver->calls);
        self::assertSame('private-orders.42', $driver->calls[0]['channel']);
        self::assertSame('public-orders', $driver->calls[1]['channel']);

        foreach ($driver->calls as $call) {
            self::assertSame('order.updated', $call['event']);
            self::assertSame(['orderId' => '42', 'status' => 'shipped'], $call['payload']);
        }
    }
}
