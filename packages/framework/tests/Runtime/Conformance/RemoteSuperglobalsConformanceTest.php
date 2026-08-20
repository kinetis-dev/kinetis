<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Conformance;

use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;

/**
 * The shared conformance suite against a real SAPI someone else started
 * — a FrankenPHP worker, or PHP-FPM behind nginx — serving
 * Fixtures/index.php. The integration workflow starts each one in a
 * container and points this class at it; with nothing pointed at, the
 * whole class skips, the same environment-gating the persistence
 * package's database tests use.
 *
 *   KINETIS_CONFORMANCE_HOST       host:port the server listens on
 *   KINETIS_CONFORMANCE_STATE_DIR  the fixture's state directory, as this
 *                                  process sees it (the container was
 *                                  given its own path to the same place)
 *   KINETIS_CONFORMANCE_CLIENT_IP  what the server reports as REMOTE_ADDR
 *                                  for a connection from this process
 */
final class RemoteSuperglobalsConformanceTest extends RuntimeAdapterConformanceTestCase
{
    private static ?SuperglobalsDriver $driver = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('KINETIS_CONFORMANCE_HOST');
        $stateDir = getenv('KINETIS_CONFORMANCE_STATE_DIR');

        if (!is_string($host) || $host === '' || !is_string($stateDir) || $stateDir === '') {
            self::markTestSkipped('KINETIS_CONFORMANCE_HOST/KINETIS_CONFORMANCE_STATE_DIR not set — no real SAPI to run against.');
        }

        $clientIp = getenv('KINETIS_CONFORMANCE_CLIENT_IP');

        self::$driver = SuperglobalsDriver::against($host, $stateDir, is_string($clientIp) && $clientIp !== '' ? $clientIp : '127.0.0.1');
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
