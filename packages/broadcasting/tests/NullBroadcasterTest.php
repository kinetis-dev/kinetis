<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\NullBroadcaster;
use PHPUnit\Framework\TestCase;

final class NullBroadcasterTest extends TestCase
{
    public function test_broadcast_is_a_silent_no_op(): void
    {
        $this->expectNotToPerformAssertions();

        new NullBroadcaster()->broadcast('orders', 'order.updated', ['status' => 'shipped']);
    }
}
