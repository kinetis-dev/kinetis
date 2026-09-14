<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Kinetis\DatabaseBridge\TelemetrySqlInstrumentation;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelemetrySqlInstrumentationTest extends TestCase
{
    public function test_every_moment_reaches_its_telemetry_hook_with_its_arguments(): void
    {
        $failure = new RuntimeException('lost');
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects(self::once())->method('queryDispatched')->with('mysql', 'SELECT 1')->willReturn('query');
        $telemetry->expects(self::once())->method('queryServerStarted')->with('query');
        $telemetry->expects(self::once())->method('queryReaped')->with('query', $failure);
        $telemetry->expects(self::once())->method('transactionStarted')->with('postgresql')->willReturn('transaction');
        $telemetry->expects(self::once())->method('transactionEnded')->with('transaction', 'rollback');

        $instrumentation = new TelemetrySqlInstrumentation($telemetry);

        $token = $instrumentation->queryDispatched('mysql', 'SELECT 1');
        $instrumentation->queryServerStarted($token);
        $instrumentation->queryReaped($token, $failure);
        $instrumentation->transactionEnded($instrumentation->transactionStarted('postgresql'), 'rollback');
    }

    /** kinetis/telemetry swaps its backend in after package bootstraps have built their clients. */
    public function test_a_backend_swapped_into_the_holder_later_receives_the_moments(): void
    {
        $holder = new Telemetry();
        $instrumentation = new TelemetrySqlInstrumentation($holder);

        $backend = $this->createMock(TelemetryInterface::class);
        $backend->expects(self::once())->method('queryDispatched')->with('postgresql', 'SELECT 2')->willReturn('late');
        $holder->swap($backend);

        self::assertSame('late', $instrumentation->queryDispatched('postgresql', 'SELECT 2'));
    }
}
