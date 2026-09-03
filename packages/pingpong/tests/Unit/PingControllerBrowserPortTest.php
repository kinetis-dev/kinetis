<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\PingController;
use InvalidArgumentException;
use Kinetis\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PingController::browserPort() in isolation — a plain Config in, an int
 * out — rather than through a full request, which would need a real
 * database.
 */
final class PingControllerBrowserPortTest extends TestCase
{
    public function test_the_default_port_is_used_when_unset(): void
    {
        self::assertSame(6001, PingController::browserPort(new Config([])));
    }

    public function test_a_valid_configured_port_is_used(): void
    {
        self::assertSame(9000, PingController::browserPort(new Config(['BROADCAST_BROWSER_PORT' => '9000'])));
    }

    /**
     * @return list<array{string}>
     */
    public static function outOfRangePortCases(): array
    {
        return [
            'zero' => ['0'],
            'beyond 65535' => ['65536'],
        ];
    }

    #[DataProvider('outOfRangePortCases')]
    public function test_a_port_outside_the_valid_tcp_range_throws(string $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BROADCAST_BROWSER_PORT must be a valid TCP port');

        PingController::browserPort(new Config(['BROADCAST_BROWSER_PORT' => $port]));
    }
}
