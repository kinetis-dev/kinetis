<?php

declare(strict_types=1);

namespace Kinetis\Instrumentation;

use Throwable;

/**
 * The holder every call site actually talks to: delegates each hook to
 * the current backend, {@see NullTelemetry} until something swaps a
 * real one in. Existing as a stable indirection is the point — call
 * sites (and package bootstraps that construct services early) hold
 * this object once, and a backend swapped in later is seen by all of
 * them regardless of the order anything was constructed in.
 *
 * `global()` is deliberately a per-process singleton (per PHP thread on
 * a ZTS build, matching the one-loop-per-thread model): worker-lifetime
 * infrastructure like {@see \Kinetis\Async\FiberPool}, not request
 * state — the backend reference is configuration set once at boot, and
 * no request data lives here. It exists because two kinds of call site
 * have no injection point at all: plain functions (`concurrently()`)
 * and the pre-container lifecycle phases entry points measure.
 */
final class Telemetry implements TelemetryInterface
{
    private static ?self $instance = null;

    private TelemetryInterface $backend;

    public function __construct(?TelemetryInterface $backend = null)
    {
        $this->backend = $backend ?? new NullTelemetry();
    }

    public static function global(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Installs a real backend — what kinetis/telemetry's package
     * bootstrap calls. Every holder of this instance sees it from the
     * next hook on.
     */
    public function swap(TelemetryInterface $backend): void
    {
        $this->backend = $backend;
    }

    #[\Override]
    public function phase(string $name, float $startedAt, float $endedAt): void
    {
        $this->backend->phase($name, $startedAt, $endedAt);
    }

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        return $this->backend->routeMatchStarted($method, $path);
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void
    {
        $this->backend->routeMatchEnded($token, $pattern);
    }

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        return $this->backend->middlewareEntered($class);
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void
    {
        $this->backend->middlewareExited($token, $failure);
    }

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        return $this->backend->hydrationStarted($dtoClass);
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void
    {
        $this->backend->hydrationEnded($token);
    }

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        return $this->backend->controllerInvoked($class, $method);
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->backend->controllerReturned($token, $failure);
    }

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        return $this->backend->responseEncodingStarted();
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void
    {
        $this->backend->responseEncodingEnded($token);
    }

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        return $this->backend->queryDispatched($system, $sql);
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->backend->queryServerStarted($token);
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->backend->queryReaped($token, $failure);
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        return $this->backend->transactionStarted($system);
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->backend->transactionEnded($token, $outcome);
    }

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        return $this->backend->taskBatchStarted($count);
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void
    {
        $this->backend->taskBatchEnded($token);
    }

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        return $this->backend->taskStarted($index);
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void
    {
        $this->backend->taskEnded($token, $failure);
    }

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        return $this->backend->eventDispatched($eventClass);
    }

    #[\Override]
    public function eventSettled(mixed $token): void
    {
        $this->backend->eventSettled($token);
    }

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        return $this->backend->listenerInvoked($listenerClass, $method);
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->backend->listenerReturned($token, $failure);
    }

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        return $this->backend->toolCallStarted($tool);
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void
    {
        $this->backend->toolCallEnded($token, $failure);
    }

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        return $this->backend->resourceReadStarted($uri);
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void
    {
        $this->backend->resourceReadEnded($token);
    }

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        return $this->backend->jobPushStarted($jobClass, $queue);
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void
    {
        $this->backend->jobPushEnded($token, $failure);
    }

    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt): mixed
    {
        return $this->backend->jobStarted($jobClass, $queue, $attempt);
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void
    {
        $this->backend->jobFinished($token, $outcome, $failure);
    }
}
