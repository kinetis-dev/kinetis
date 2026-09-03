<?php

declare(strict_types=1);

namespace Kinetis\Tests\Instrumentation;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * The seam every framework hook runs through. Three properties matter
 * and none is obvious from reading one method: the holder must forward
 * every hook to whatever backend is installed; the default backend must
 * do nothing at all — an application with kinetis/telemetry absent still
 * calls these on every request; and a backend that fails must never let
 * that failure become application behavior — instrumentation is
 * observational, not a decision-maker.
 *
 * All three are checked by reflecting over the interface rather than by
 * listing 30-odd methods by hand, so a hook added later is covered the
 * day it is added instead of being quietly forgotten.
 */
final class TelemetryHolderTest extends TestCase
{
    /**
     * @return list<ReflectionMethod>
     */
    private static function hooks(): array
    {
        return new ReflectionClass(TelemetryInterface::class)->getMethods();
    }

    /**
     * @return list<mixed>
     */
    private static function argumentsFor(ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

            $arguments[] = match ($name) {
                'string' => 'probe',
                'int' => 1,
                'float' => 1.0,
                'bool' => true,
                'array' => [],
                default => $parameter->allowsNull() ? null : 'probe',
            };
        }

        return $arguments;
    }

    public function test_the_default_backend_does_nothing_and_throws_nothing(): void
    {
        $telemetry = new Telemetry();

        foreach (self::hooks() as $hook) {
            $result = $telemetry->{$hook->getName()}(...self::argumentsFor($hook));

            // A hook that opens a span returns an opaque token, a hook
            // that closes one returns nothing, and jobPushMetadata()
            // returns a carrier to attach to the job. The no-op backend
            // must produce nothing meaningful for any of them: null, or
            // an empty carrier with no trace to propagate.
            self::assertTrue(
                $result === null || $result === [],
                "{$hook->getName()}() returned a meaningful value from the no-op backend",
            );
        }
    }

    public function test_every_hook_is_forwarded_to_the_installed_backend(): void
    {
        $recording = new RecordingTelemetry();
        $telemetry = new Telemetry();
        $telemetry->swap($recording);

        $hooks = self::hooks();

        foreach ($hooks as $hook) {
            $telemetry->{$hook->getName()}(...self::argumentsFor($hook));
        }

        self::assertSame(
            count($hooks),
            count($recording->calls),
            'a hook on TelemetryInterface is not forwarded by Telemetry',
        );
    }

    public function test_swapping_replaces_the_backend_rather_than_adding_one(): void
    {
        $first = new RecordingTelemetry();
        $second = new RecordingTelemetry();

        $telemetry = new Telemetry($first);
        $telemetry->swap($second);
        $telemetry->phase('boot', 1.0, 2.0);

        self::assertSame([], $first->calls, 'the replaced backend still received a hook');
        self::assertCount(1, $second->calls);
    }

    /**
     * The holder is reachable from plain functions and from code running
     * before any container exists, which is why it has a process-wide
     * instance at all.
     */
    public function test_the_global_holder_is_the_same_instance_every_time(): void
    {
        self::assertSame(Telemetry::global(), Telemetry::global());
    }

    /**
     * Telemetry is the one place a backend's own failure is contained —
     * instrumentation must never become application behavior. A void
     * hook completes normally rather than propagating the backend's
     * exception.
     */
    public function test_a_failing_backend_is_contained_not_propagated(): void
    {
        $telemetry = new Telemetry(new ThrowingTelemetry());

        $telemetry->phase('boot', 1.0, 2.0);

        self::assertTrue(true, 'reaching this line proves phase() did not propagate the backend failure');
    }

    /**
     * Every hook on the interface, not just phase() — reflected the same
     * way test_every_hook_is_forwarded_to_the_installed_backend() already
     * is, so a hook added later is covered automatically rather than
     * silently leaving one pair unguarded. Covers all three return
     * shapes TelemetryInterface actually has: void hooks complete
     * normally, token-returning hooks fall back to null, and
     * jobPushMetadata() falls back to an empty array.
     */
    public function test_every_hook_contains_a_failing_backend_and_falls_back_safely(): void
    {
        $telemetry = new Telemetry(new ThrowingTelemetry());

        foreach (self::hooks() as $hook) {
            $result = $telemetry->{$hook->getName()}(...self::argumentsFor($hook));

            if ($hook->getName() === 'jobPushMetadata') {
                self::assertSame([], $result, "{$hook->getName()}() did not fall back to an empty array");

                continue;
            }

            self::assertNull($result, "{$hook->getName()}() did not fall back to null on a failing backend");
        }
    }

    /**
     * swap() is configuration, not a backend call — it must never be
     * treated as one of the guarded hooks, and a backend that throws from
     * every real hook must not make swapping to (or away from) it fail
     * either.
     */
    public function test_swap_itself_is_never_guarded_or_affected_by_a_failing_backend(): void
    {
        $telemetry = new Telemetry(new ThrowingTelemetry());
        $recording = new RecordingTelemetry();

        $telemetry->swap($recording);
        $telemetry->phase('boot', 1.0, 2.0);

        self::assertCount(1, $recording->calls, 'swap() genuinely replaced the failing backend');
    }

    /**
     * A backend's exception message is not framework-controlled content —
     * it can legitimately embed SQL text, a job's metadata, a credential,
     * or a controller argument the backend happened to include while
     * describing its own failure. The diagnostic `reportHookFailure()`
     * writes must identify *what* failed (the hook, the backend's class,
     * the exception's class) without ever repeating *why* the backend
     * says it failed. Proven here with a message carrying a distinctive
     * secret that must never reach the log, not merely asserted from the
     * source.
     */
    public function test_the_failure_diagnostic_never_includes_the_backends_exception_message(): void
    {
        $secret = 'SECRET_TOKEN_9f3a7c1e_password=hunter2_sql=SELECT_FROM_users';
        $logFile = tempnam(sys_get_temp_dir(), 'kinetis-telemetry-log-');
        self::assertIsString($logFile);

        $previousErrorLog = ini_set('error_log', $logFile);
        self::assertIsString($previousErrorLog, 'could not redirect error_log() for this test');

        try {
            $telemetry = new Telemetry(new ThrowingTelemetry($secret));

            $telemetry->phase('boot', 1.0, 2.0);

            $logged = file_get_contents($logFile);
            self::assertIsString($logged);

            self::assertStringNotContainsString($secret, $logged, 'the backend exception message leaked into the diagnostic');
            self::assertStringContainsString('phase', $logged, 'the diagnostic does not name the failed hook');
            self::assertStringContainsString(ThrowingTelemetry::class, $logged, 'the diagnostic does not name the failing backend class');
            self::assertStringContainsString(RuntimeException::class, $logged, 'the diagnostic does not name the exception class');
        } finally {
            ini_set('error_log', $previousErrorLog);
            unlink($logFile);
        }
    }
}
