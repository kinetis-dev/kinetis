<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Broadcasting\DemoVisitor;
use App\Broadcasting\NotificationChannelAuthorizer;
use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NotificationChannelAuthorizerTest extends TestCase
{
    public function test_authorizes_any_visitor(): void
    {
        $authorized = new NotificationChannelAuthorizer()->authorize(new DemoVisitor());

        self::assertTrue($authorized);
    }

    public function test_is_discoverable_as_the_notifications_channels_authorizer(): void
    {
        $method = new ReflectionMethod(NotificationChannelAuthorizer::class, 'authorize');
        $attributes = $method->getAttributes(BroadcastChannel::class);

        self::assertCount(1, $attributes);
        self::assertSame('notifications', $attributes[0]->newInstance()->pattern);
    }
}
