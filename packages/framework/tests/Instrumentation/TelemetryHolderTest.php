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
 * The seam every framework hook runs through. Two properties matter and
 * neither is obvious from reading one method: the holder must forward
 * every hook to whatever backend is installed, and the default backend
 * must do nothing at all — an application with kinetis/telemetry absent
 * still calls these on every request.
 *
 * Both are checked by reflecting over the interface rather than by
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

    public function test_a_failing_backend_is_not_swallowed(): void
    {
        $backend = $this->createMock(TelemetryInterface::class);
        $backend->method('phase')->willThrowException(new RuntimeException('backend failed'));

        $telemetry = new Telemetry($backend);

        $this->expectExceptionMessage('backend failed');

        $telemetry->phase('boot', 1.0, 2.0);
    }
}
