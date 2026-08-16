<?php

declare(strict_types=1);

namespace Kinetis\Instrumentation;

use Throwable;

/**
 * The default backend: every hook is a no-op and every token is null.
 * What an application runs until (and unless) kinetis/telemetry swaps
 * in a real backend.
 */
final class NullTelemetry implements TelemetryInterface
{
    #[\Override]
    public function phase(string $name, float $startedAt, float $endedAt): void {}

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        return null;
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void {}

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        return null;
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        return null;
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void {}

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        return null;
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        return null;
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void {}

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        return null;
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void {}

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        return null;
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void {}

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        return null;
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void {}

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        return null;
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        return null;
    }

    #[\Override]
    public function eventSettled(mixed $token): void {}

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        return null;
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        return null;
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void {}

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        return null;
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void {}

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jobPushMetadata(mixed $token): array
    {
        return [];
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void {}

    /**
     * @param array<string, string> $metadata
     */
    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed
    {
        return null;
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void {}
}
