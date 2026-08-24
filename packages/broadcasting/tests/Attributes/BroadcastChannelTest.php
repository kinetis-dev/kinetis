<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Attributes;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use PHPUnit\Framework\TestCase;

final class BroadcastChannelTest extends TestCase
{
    public function test_carries_the_pattern_verbatim(): void
    {
        $attribute = new BroadcastChannel('orders.{orderId}');

        self::assertSame('orders.{orderId}', $attribute->pattern);
    }
}
