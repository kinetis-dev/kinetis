<?php

declare(strict_types=1);

namespace Kinetis\Tests\Logging;

use Kinetis\Logging\SafeLogger;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Fixtures\ThrowingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

final class SafeLoggerTest extends TestCase
{
    public function test_a_healthy_logger_receives_the_level_message_and_context(): void
    {
        $logger = new InMemoryLogger();

        SafeLogger::log($logger, LogLevel::WARNING, 'disk is filling up', ['free' => '2%']);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('disk is filling up', $logger->records[0]['message']);
        self::assertSame(['free' => '2%'], $logger->records[0]['context']);
    }

    /**
     * The entire point of this class: a logger that throws must not
     * propagate — the caller is always a terminal/fallback boundary that
     * has already decided its own outcome (a 500, a regenerated
     * document), and this logging attempt is diagnostic only.
     */
    public function test_a_throwing_logger_does_not_propagate(): void
    {
        $logger = new ThrowingLogger();

        SafeLogger::log($logger, LogLevel::ERROR, 'unhandled exception', []);

        // No exception reached this point — the assertion below just
        // confirms the call was genuinely attempted, not skipped.
        self::assertCount(1, $logger->entries);
    }

    public function test_logFrom_delivers_the_level_message_and_context_when_the_resolver_succeeds(): void
    {
        $logger = new InMemoryLogger();

        SafeLogger::logFrom(static fn () => $logger, LogLevel::WARNING, 'disk is filling up', ['free' => '2%']);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('disk is filling up', $logger->records[0]['message']);
        self::assertSame(['free' => '2%'], $logger->records[0]['context']);
    }

    public function test_logFrom_does_not_propagate_when_the_resolved_loggers_own_log_call_throws(): void
    {
        $logger = new ThrowingLogger();

        SafeLogger::logFrom(static fn () => $logger, LogLevel::ERROR, 'unhandled exception', []);

        self::assertCount(1, $logger->entries);
    }

    /**
     * The actual gap logFrom() exists to close: SafeLogger::log($container
     * ->get(LoggerInterface::class), ...) is not safe on its own, since PHP
     * evaluates that argument before log() is ever entered — a throwing
     * resolver (a broken logger binding/factory) must be contained too,
     * not just the resolved logger's own log() call.
     */
    public function test_logFrom_does_not_propagate_when_the_resolver_itself_throws(): void
    {
        $resolveLogger = static function (): never {
            throw new RuntimeException('logger factory failed');
        };

        SafeLogger::logFrom($resolveLogger, LogLevel::ERROR, 'unhandled exception', []);

        // No exception reached this point — that is the entire assertion.
        self::assertTrue(true);
    }
}
