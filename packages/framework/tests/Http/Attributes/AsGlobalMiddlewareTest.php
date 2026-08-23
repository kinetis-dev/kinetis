<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Attributes;

use InvalidArgumentException;
use Kinetis\Http\Attributes\AsGlobalMiddleware;
use PHPUnit\Framework\TestCase;

final class AsGlobalMiddlewareTest extends TestCase
{
    public function test_defaults_to_priority_50(): void
    {
        self::assertSame(50, new AsGlobalMiddleware()->priority);
    }

    public function test_accepts_the_full_0_to_100_range(): void
    {
        self::assertSame(0, new AsGlobalMiddleware(priority: 0)->priority);
        self::assertSame(100, new AsGlobalMiddleware(priority: 100)->priority);
    }

    public function test_rejects_a_priority_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsGlobalMiddleware(priority: -1);
    }

    public function test_rejects_a_priority_above_100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsGlobalMiddleware(priority: 101);
    }
}
