<?php

declare(strict_types=1);

namespace Kinetis\Instrumentation;

use Throwable;

/**
 * The framework's instrumentation vocabulary: named moments core (and
 * the persistence/queue packages) report as they happen, for a
 * telemetry backend to turn into spans, timings, or nothing at all.
 *
 * Hooks come in started/ended pairs joined by an opaque `mixed` token —
 * whatever the started call returns is handed back to the ended call,
 * and no caller ever inspects it. `phase()` is the one exception: the
 * earliest lifecycle phases run before any telemetry backend can exist,
 * so entry points measure them with plain timestamps and report them
 * after the fact.
 *
 * Implemented by {@see NullTelemetry} and by kinetis/telemetry's
 * OTel-backed implementation — and by nothing else. This is not a
 * consumer extension point: the hook set is under evaluation and will
 * shrink as measurements decide which hooks earn their cost, so
 * third-party implementations would break on a minor release. An
 * application wanting telemetry data somewhere else consumes it from
 * the exporting backend, not by implementing this interface.
 */
interface TelemetryInterface
{
    /**
     * A lifecycle phase measured before a backend existed — discovery,
     * bootstrap, cache load — reported after the fact with real
     * timestamps (microtime(true) values).
     */
    public function phase(string $name, float $startedAt, float $endedAt): void;

    public function routeMatchStarted(string $method, string $path): mixed;

    public function routeMatchEnded(mixed $token, ?string $pattern): void;

    public function middlewareEntered(string $class): mixed;

    public function middlewareExited(mixed $token, ?Throwable $failure): void;

    public function hydrationStarted(string $dtoClass): mixed;

    public function hydrationEnded(mixed $token): void;

    public function controllerInvoked(string $class, string $method): mixed;

    public function controllerReturned(mixed $token, ?Throwable $failure): void;

    public function responseEncodingStarted(): mixed;

    public function responseEncodingEnded(mixed $token): void;

    /**
     * A query handed to a driver. `queryServerStarted()` marks the
     * moment it actually went to the server — the gap in between is
     * time spent waiting for a free connection.
     */
    public function queryDispatched(string $system, string $sql): mixed;

    public function queryServerStarted(mixed $token): void;

    public function queryReaped(mixed $token, ?Throwable $failure): void;

    public function transactionStarted(string $system): mixed;

    /** $outcome is 'commit' or 'rollback'. */
    public function transactionEnded(mixed $token, string $outcome): void;

    /** A concurrently() batch. */
    public function taskBatchStarted(int $count): mixed;

    public function taskBatchEnded(mixed $token): void;

    /** One task within a concurrently() batch. */
    public function taskStarted(int $index): mixed;

    public function taskEnded(mixed $token, ?Throwable $failure): void;

    public function eventDispatched(string $eventClass): mixed;

    public function eventSettled(mixed $token): void;

    public function listenerInvoked(string $listenerClass, string $method): mixed;

    public function listenerReturned(mixed $token, ?Throwable $failure): void;

    public function toolCallStarted(string $tool): mixed;

    public function toolCallEnded(mixed $token, ?Throwable $failure): void;

    public function resourceReadStarted(string $uri): mixed;

    public function resourceReadEnded(mixed $token): void;

    public function jobPushStarted(string $jobClass, string $queue): mixed;

    /**
     * Opaque string metadata for the backend to store alongside the job
     * being pushed — the propagation channel that lets a consumer span
     * on a worker, seconds later and in another process, join the
     * producer's trace. A backend stores whatever this returns verbatim
     * and hands it back through jobStarted()'s $metadata; nothing in the
     * queue layer ever interprets it.
     *
     * @return array<string, string>
     */
    public function jobPushMetadata(mixed $token): array;

    public function jobPushEnded(mixed $token, ?Throwable $failure): void;

    /**
     * @param array<string, string> $metadata what jobPushMetadata()
     *     returned at push time, carried through the backend's payload
     */
    public function jobStarted(string $jobClass, string $queue, int $attempt, array $metadata = []): mixed;

    /** $outcome is 'ack', 'release', or 'fail'. */
    public function jobFinished(mixed $token, string $outcome, ?Throwable $failure): void;
}
