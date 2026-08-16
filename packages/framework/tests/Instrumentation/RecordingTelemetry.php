<?php

declare(strict_types=1);

namespace Kinetis\Tests\Instrumentation;

use Kinetis\Instrumentation\TelemetryInterface;
use Throwable;

/** Records every hook call, with an incrementing int as its token. */
final class RecordingTelemetry implements TelemetryInterface
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    private int $nextToken = 1;

    /**
     * @return ?array{string, list<mixed>}
     */
    public function firstCall(string $hook): ?array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === $hook) {
                return $call;
            }
        }

        return null;
    }

    #[\Override]
    public function phase(string $name, float $startedAt, float $endedAt): void
    {
        $this->calls[] = ['phase', [$name, $startedAt, $endedAt]];
    }

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        $this->calls[] = ['routeMatchStarted', [$method, $path]];

        return $this->nextToken++;
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void
    {
        $this->calls[] = ['routeMatchEnded', [$token, $pattern]];
    }

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        $this->calls[] = ['middlewareEntered', [$class]];

        return $this->nextToken++;
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['middlewareExited', [$token, $failure]];
    }

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        $this->calls[] = ['hydrationStarted', [$dtoClass]];

        return $this->nextToken++;
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void
    {
        $this->calls[] = ['hydrationEnded', [$token]];
    }

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        $this->calls[] = ['controllerInvoked', [$class, $method]];

        return $this->nextToken++;
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['controllerReturned', [$token, $failure]];
    }

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        $this->calls[] = ['responseEncodingStarted', []];

        return $this->nextToken++;
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void
    {
        $this->calls[] = ['responseEncodingEnded', [$token]];
    }

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        $this->calls[] = ['queryDispatched', [$system, $sql]];

        return $this->nextToken++;
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->calls[] = ['queryServerStarted', [$token]];
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['queryReaped', [$token, $failure]];
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        $this->calls[] = ['transactionStarted', [$system]];

        return $this->nextToken++;
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->calls[] = ['transactionEnded', [$token, $outcome]];
    }

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        $this->calls[] = ['taskBatchStarted', [$count]];

        return $this->nextToken++;
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void
    {
        $this->calls[] = ['taskBatchEnded', [$token]];
    }

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        $this->calls[] = ['taskStarted', [$index]];

        return $this->nextToken++;
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['taskEnded', [$token, $failure]];
    }

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        $this->calls[] = ['eventDispatched', [$eventClass]];

        return $this->nextToken++;
    }

    #[\Override]
    public function eventSettled(mixed $token): void
    {
        $this->calls[] = ['eventSettled', [$token]];
    }

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        $this->calls[] = ['listenerInvoked', [$listenerClass, $method]];

        return $this->nextToken++;
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['listenerReturned', [$token, $failure]];
    }

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        $this->calls[] = ['toolCallStarted', [$tool]];

        return $this->nextToken++;
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['toolCallEnded', [$token, $failure]];
    }

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        $this->calls[] = ['resourceReadStarted', [$uri]];

        return $this->nextToken++;
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void
    {
        $this->calls[] = ['resourceReadEnded', [$token]];
    }

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        $this->calls[] = ['jobPushStarted', [$jobClass, $queue]];

        return $this->nextToken++;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jobPushMetadata(mixed $token): array
    {
        $this->calls[] = ['jobPushMetadata', [$token]];

        return ['traceparent' => 'recorded-' . var_export($token, true)];
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void
    {
        $this->calls[] = ['jobPushEnded', [$token, $failure]];
    }

    /**
     * @param array<string, string> $metadata
     */
    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed
    {
        $this->calls[] = ['jobStarted', [$jobClass, $queue, $attempt, $metadata]];

        return $this->nextToken++;
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void
    {
        $this->calls[] = ['jobFinished', [$token, $outcome, $failure]];
    }
}
