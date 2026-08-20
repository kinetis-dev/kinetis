<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Conformance;

use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;

/**
 * The shared conformance suite against the superglobals adapters under a
 * spawned `php -S` — see {@see SuperglobalsDriver} for what that proves
 * and what it doesn't. The real SAPIs run the same suite through
 * {@see RemoteSuperglobalsConformanceTest}. One fixture server for the
 * whole class; each dispatch is its own HTTP request.
 */
final class SuperglobalsConformanceTest extends RuntimeAdapterConformanceTestCase
{
    private static ?SuperglobalsDriver $driver = null;

    public static function setUpBeforeClass(): void
    {
        self::$driver = SuperglobalsDriver::spawn();
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
        return self::$driver ?? throw new \LogicException('driver not started');
    }
}
