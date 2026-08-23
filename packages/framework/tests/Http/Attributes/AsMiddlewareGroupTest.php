<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Attributes;

use InvalidArgumentException;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use PHPUnit\Framework\TestCase;

final class AsMiddlewareGroupTest extends TestCase
{
    public function test_defaults_to_priority_50(): void
    {
        self::assertSame(50, new AsMiddlewareGroup('admin')->priority);
    }

    public function test_keeps_the_given_group_name(): void
    {
        self::assertSame('admin', new AsMiddlewareGroup('admin')->name);
    }

    public function test_accepts_the_full_0_to_100_range(): void
    {
        self::assertSame(0, new AsMiddlewareGroup('admin', priority: 0)->priority);
        self::assertSame(100, new AsMiddlewareGroup('admin', priority: 100)->priority);
    }

    public function test_rejects_a_priority_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsMiddlewareGroup('admin', priority: -1);
    }

    public function test_rejects_a_priority_above_100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsMiddlewareGroup('admin', priority: 101);
    }

    public function test_rejects_an_empty_group_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AsMiddlewareGroup('');
    }
}
