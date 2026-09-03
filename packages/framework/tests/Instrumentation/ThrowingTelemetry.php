<?php

declare(strict_types=1);

namespace Kinetis\Tests\Instrumentation;

use Kinetis\Instrumentation\TelemetryInterface;
use RuntimeException;
use Throwable;

/**
 * Every hook throws immediately — a deliberately broken backend. The
 * exception message is configurable so a test can prove `Telemetry`'s
 * own diagnostic never leaks it (a backend's exception message can
 * legitimately carry SQL, a job's metadata, a credential, or a
 * controller argument, none of which `Telemetry::reportHookFailure()`
 * is allowed to surface).
 */
final class ThrowingTelemetry implements TelemetryInterface
{
    public function __construct(
        private readonly string $message = 'backend failed',
    ) {
    }

    private function fail(): never
    {
        throw new RuntimeException($this->message);
    }

    #[\Override]
    public function phase(string $name, float $startedAt, float $endedAt): void
    {
        $this->fail();
    }

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void
    {
        $this->fail();
    }

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->fail();
    }

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function eventSettled(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void
    {
        $this->fail();
    }

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        $this->fail();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jobPushMetadata(mixed $token): array
    {
        $this->fail();
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void
    {
        $this->fail();
    }

    /**
     * @param array<string, string> $metadata
     */
    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed
    {
        $this->fail();
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void
    {
        $this->fail();
    }
}
