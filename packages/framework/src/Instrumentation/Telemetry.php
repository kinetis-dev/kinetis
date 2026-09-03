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
 *
 * This class is also the one place a backend's own failure is contained.
 * Instrumentation is observational — it must never become application
 * behavior — but every call site throughout the framework (`Kernel`,
 * `Dispatcher`, `MiddlewarePipeline`, `ConcurrentBatch`/`concurrently()`,
 * every queue producer, the persistence drivers, events, MCP) calls a
 * hook either inside a `try`/`catch`/`finally` around real work, or
 * right before deciding what to do next. A throwing backend, unguarded,
 * would let that exception replace the real controller/router/task/job
 * outcome, or run a durable operation's own completion hook twice. Every
 * void/end hook here therefore catches and discards a backend failure;
 * every token-returning start hook returns `null` instead — a harmless
 * sentinel every real backend's own end hook already tolerates the same
 * way `NullTelemetry` does; `jobPushMetadata()` falls back to an empty
 * array. `swap()` is configuration, not a backend call, and is not
 * guarded. A caught failure is reported once, in `reportHookFailure()`,
 * which never calls back into telemetry (that could recurse into the
 * very failure being reported) and never resolves an application logger
 * (which may itself be instrumented, or itself broken) — only a direct
 * `error_log()` call naming the hook, the backend's class, and the
 * exception's own class. Deliberately never the exception's *message*,
 * and never the hook's own call arguments (SQL text, job metadata, a
 * controller argument, a URL) — any of those can carry sensitive data,
 * and a backend's exception message is attacker-reachable content in
 * some call paths, not just internal diagnostic text.
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
        $this->guarded('phase', function () use ($name, $startedAt, $endedAt): void {
            $this->backend->phase($name, $startedAt, $endedAt);
        });
    }

    #[\Override]
    public function routeMatchStarted(string $method, string $path): mixed
    {
        return $this->guarded('routeMatchStarted', fn (): mixed => $this->backend->routeMatchStarted($method, $path));
    }

    #[\Override]
    public function routeMatchEnded(mixed $token, ?string $pattern): void
    {
        $this->guarded('routeMatchEnded', function () use ($token, $pattern): void {
            $this->backend->routeMatchEnded($token, $pattern);
        });
    }

    #[\Override]
    public function middlewareEntered(string $class): mixed
    {
        return $this->guarded('middlewareEntered', fn (): mixed => $this->backend->middlewareEntered($class));
    }

    #[\Override]
    public function middlewareExited(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('middlewareExited', function () use ($token, $failure): void {
            $this->backend->middlewareExited($token, $failure);
        });
    }

    #[\Override]
    public function hydrationStarted(string $dtoClass): mixed
    {
        return $this->guarded('hydrationStarted', fn (): mixed => $this->backend->hydrationStarted($dtoClass));
    }

    #[\Override]
    public function hydrationEnded(mixed $token): void
    {
        $this->guarded('hydrationEnded', function () use ($token): void {
            $this->backend->hydrationEnded($token);
        });
    }

    #[\Override]
    public function controllerInvoked(string $class, string $method): mixed
    {
        return $this->guarded('controllerInvoked', fn (): mixed => $this->backend->controllerInvoked($class, $method));
    }

    #[\Override]
    public function controllerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('controllerReturned', function () use ($token, $failure): void {
            $this->backend->controllerReturned($token, $failure);
        });
    }

    #[\Override]
    public function responseEncodingStarted(): mixed
    {
        return $this->guarded('responseEncodingStarted', fn (): mixed => $this->backend->responseEncodingStarted());
    }

    #[\Override]
    public function responseEncodingEnded(mixed $token): void
    {
        $this->guarded('responseEncodingEnded', function () use ($token): void {
            $this->backend->responseEncodingEnded($token);
        });
    }

    #[\Override]
    public function queryDispatched(string $system, string $sql): mixed
    {
        return $this->guarded('queryDispatched', fn (): mixed => $this->backend->queryDispatched($system, $sql));
    }

    #[\Override]
    public function queryServerStarted(mixed $token): void
    {
        $this->guarded('queryServerStarted', function () use ($token): void {
            $this->backend->queryServerStarted($token);
        });
    }

    #[\Override]
    public function queryReaped(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('queryReaped', function () use ($token, $failure): void {
            $this->backend->queryReaped($token, $failure);
        });
    }

    #[\Override]
    public function transactionStarted(string $system): mixed
    {
        return $this->guarded('transactionStarted', fn (): mixed => $this->backend->transactionStarted($system));
    }

    #[\Override]
    public function transactionEnded(mixed $token, string $outcome): void
    {
        $this->guarded('transactionEnded', function () use ($token, $outcome): void {
            $this->backend->transactionEnded($token, $outcome);
        });
    }

    #[\Override]
    public function taskBatchStarted(int $count): mixed
    {
        return $this->guarded('taskBatchStarted', fn (): mixed => $this->backend->taskBatchStarted($count));
    }

    #[\Override]
    public function taskBatchEnded(mixed $token): void
    {
        $this->guarded('taskBatchEnded', function () use ($token): void {
            $this->backend->taskBatchEnded($token);
        });
    }

    #[\Override]
    public function taskStarted(int $index): mixed
    {
        return $this->guarded('taskStarted', fn (): mixed => $this->backend->taskStarted($index));
    }

    #[\Override]
    public function taskEnded(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('taskEnded', function () use ($token, $failure): void {
            $this->backend->taskEnded($token, $failure);
        });
    }

    #[\Override]
    public function eventDispatched(string $eventClass): mixed
    {
        return $this->guarded('eventDispatched', fn (): mixed => $this->backend->eventDispatched($eventClass));
    }

    #[\Override]
    public function eventSettled(mixed $token): void
    {
        $this->guarded('eventSettled', function () use ($token): void {
            $this->backend->eventSettled($token);
        });
    }

    #[\Override]
    public function listenerInvoked(string $listenerClass, string $method): mixed
    {
        return $this->guarded('listenerInvoked', fn (): mixed => $this->backend->listenerInvoked($listenerClass, $method));
    }

    #[\Override]
    public function listenerReturned(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('listenerReturned', function () use ($token, $failure): void {
            $this->backend->listenerReturned($token, $failure);
        });
    }

    #[\Override]
    public function toolCallStarted(string $tool): mixed
    {
        return $this->guarded('toolCallStarted', fn (): mixed => $this->backend->toolCallStarted($tool));
    }

    #[\Override]
    public function toolCallEnded(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('toolCallEnded', function () use ($token, $failure): void {
            $this->backend->toolCallEnded($token, $failure);
        });
    }

    #[\Override]
    public function resourceReadStarted(string $uri): mixed
    {
        return $this->guarded('resourceReadStarted', fn (): mixed => $this->backend->resourceReadStarted($uri));
    }

    #[\Override]
    public function resourceReadEnded(mixed $token): void
    {
        $this->guarded('resourceReadEnded', function () use ($token): void {
            $this->backend->resourceReadEnded($token);
        });
    }

    #[\Override]
    public function jobPushStarted(string $jobClass, string $queue): mixed
    {
        return $this->guarded('jobPushStarted', fn (): mixed => $this->backend->jobPushStarted($jobClass, $queue));
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jobPushMetadata(mixed $token): array
    {
        /** @var array<string, string> */
        return $this->guarded('jobPushMetadata', fn (): array => $this->backend->jobPushMetadata($token), []);
    }

    #[\Override]
    public function jobPushEnded(mixed $token, ?Throwable $failure): void
    {
        $this->guarded('jobPushEnded', function () use ($token, $failure): void {
            $this->backend->jobPushEnded($token, $failure);
        });
    }

    /**
     * @param array<string, string> $metadata
     */
    #[\Override]
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed
    {
        return $this->guarded('jobStarted', fn (): mixed => $this->backend->jobStarted($jobClass, $queue, $attempt, $metadata));
    }

    #[\Override]
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void
    {
        $this->guarded('jobFinished', function () use ($token, $outcome, $failure): void {
            $this->backend->jobFinished($token, $outcome, $failure);
        });
    }

    /**
     * Runs one backend hook call, containing any failure so it can never
     * become application behavior. $fallback is what a token-returning
     * hook resolves to instead — `null` by default, the sentinel every
     * end hook already tolerates the same way `NullTelemetry` does;
     * `jobPushMetadata()` passes `[]` instead, matching its own real
     * return type.
     *
     * @template T
     * @param callable(): T $call
     * @param T $fallback
     * @return T
     */
    private function guarded(string $hook, callable $call, mixed $fallback = null): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            $this->reportHookFailure($hook, $e);

            return $fallback;
        }
    }

    /**
     * The one place a contained backend failure is actually surfaced —
     * deliberately not through telemetry itself (which could recurse
     * into the very failure being reported) and not through an
     * application logger resolved from a container (which may itself be
     * instrumented, or itself be the thing that's broken). A plain,
     * direct `error_log()` call, naming only three stable,
     * framework-controlled identifiers: the hook name (a literal from
     * this class's own source), the backend's class name, and the caught
     * exception's own class name. Deliberately never the exception's
     * *message* — a backend's own exception can legitimately embed SQL
     * text, a job's metadata, a credential, a controller argument, or
     * even attacker-forged log content, none of which this diagnostic is
     * allowed to carry — and never the hook's own call arguments either,
     * for the identical reason. Wrapped so even this diagnostic can never
     * escape and become a second failure.
     */
    private function reportHookFailure(string $hook, Throwable $e): void
    {
        try {
            error_log(sprintf(
                'Kinetis telemetry hook "%s" failed on backend %s (%s)',
                $hook,
                $this->backend::class,
                $e::class,
            ));
        } catch (Throwable) {
            // Discarded deliberately — see this method's own docblock.
        }
    }
}
