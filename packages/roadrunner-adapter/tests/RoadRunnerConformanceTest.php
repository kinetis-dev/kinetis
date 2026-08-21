<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver;
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use LogicException;
use PHPUnit\Framework\SkippedTestSuiteError;

/**
 * The shared conformance suite against RoadRunnerAdapter under a real,
 * spawned `rr serve` process — see {@see RoadRunnerDriver} for what that
 * proves and what it doesn't. Skips cleanly, for the whole class, when
 * no real `rr` binary is present (see {@see RoadRunnerDriver::binaryPath()})
 * rather than failing every test with a confusing "no such file" error —
 * this repo's standard `php:8.4-cli-alpine` toolchain image has neither
 * the binary nor `ext-sockets` loaded (compilable under Alpine, just not
 * worth doing in this image; see docs/runtime-adapters.md), so this
 * suite is exercised for real only where both are actually provided.
 */
final class RoadRunnerConformanceTest extends RuntimeAdapterConformanceTestCase
{
    private static ?RoadRunnerDriver $driver = null;

    public static function setUpBeforeClass(): void
    {
        if (!RoadRunnerDriver::isBinaryAvailable()) {
            throw new SkippedTestSuiteError(
                'No real rr binary at ' . RoadRunnerDriver::binaryPath() . ' — run '
                . '"vendor/bin/rr get-binary --no-config --location ." in this package first.',
            );
        }

        self::$driver = RoadRunnerDriver::spawn();
        self::$driver->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$driver?->stop();
        self::$driver = null;
    }

    #[\Override]
    protected function driver(): RuntimeAdapterDriver
    {
        return self::$driver ?? throw new LogicException('driver not started');
    }
}
