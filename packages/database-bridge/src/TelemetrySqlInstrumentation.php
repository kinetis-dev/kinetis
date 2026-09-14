<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Persistence\Contract\SqlInstrumentation;
use Throwable;

/**
 * Reports kinetis/persistence's SQL moments as Kinetis telemetry query
 * and transaction hooks. Given the process-wide Telemetry holder, a
 * backend kinetis/telemetry swaps in after a client was built is the one
 * that client reports to.
 */
final readonly class TelemetrySqlInstrumentation implements SqlInstrumentation
{
    public function __construct(
        private TelemetryInterface $telemetry,
    ) {}

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        return $this->telemetry->queryDispatched($system, $sql);
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->telemetry->queryServerStarted($token);
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->telemetry->queryReaped($token, $failure);
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        return $this->telemetry->transactionStarted($system);
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->telemetry->transactionEnded($token, $outcome);
    }
}
