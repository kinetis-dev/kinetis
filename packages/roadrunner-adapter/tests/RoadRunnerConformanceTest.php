<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver;
use Kinetis\Testing\Runtime\AdapterRejection;
use Kinetis\Testing\Runtime\ResponseSpec;
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Kinetis\Testing\Runtime\WireRequest;
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

    /**
     * The shared suite's own `test_cookies_reach_both_the_cookie_header_and_cookie_params()`
     * is excluded from `integration.yml`'s gate — see
     * `RoadRunnerAdapter`'s own class docblock — because RoadRunner
     * represents cookies as a Go map on the way to PHP, and Go
     * randomizes map iteration order by design; that test asserts exact
     * key order, which this genuinely doesn't preserve. This is the
     * order-insensitive replacement: it proves the values themselves —
     * both cookies, correctly named, correctly valued — still survive
     * every time, which is the actual guarantee worth gating on.
     */
    public function test_cookie_values_survive_regardless_of_order(): void
    {
        $outcome = $this->driver()->dispatch(
            new WireRequest(cookies: ['kinetis_session=abc123', 'theme=dark']),
            ResponseSpec::json(200, '{"ok":true}'),
        );

        if ($outcome->response instanceof AdapterRejection) {
            self::fail("The adapter rejected the request: {$outcome->response->exceptionClass}: {$outcome->response->message}");
        }

        self::assertNotNull($outcome->observed, 'the handler never ran');

        $cookieParams = $outcome->observed->cookieParams;
        ksort($cookieParams);
        self::assertSame(['kinetis_session' => 'abc123', 'theme' => 'dark'], $cookieParams);
    }

    /**
     * `RoadRunnerAdapter::run()`'s own catch (Throwable) block — see its
     * class docblock — is the one place this adapter deliberately
     * departs from `FrankenPhpAdapter`'s convention of letting an
     * exception propagate and end the worker. Never previously proven
     * by a committed test; the manual verification this relied on isn't
     * repeatable. `Fixtures/worker.php`'s `/__conformance/throw` path
     * throws before anything else runs, carrying a message a real
     * client must never see.
     */
    public function test_a_handler_exception_produces_a_safe_response_and_the_same_worker_serves_the_next_request(): void
    {
        $failed = $this->driver()->dispatch(
            new WireRequest(path: '/__conformance/throw'),
            ResponseSpec::json(200, '{"ok":true}'),
        );

        self::assertNull($failed->observed, 'the throw happens before the fixture ever records an observed request');
        self::assertNotInstanceOf(AdapterRejection::class, $failed->response, 'RoadRunnerAdapter::run() answers via Worker::error(), a real HTTP response, not a connection-level rejection');
        self::assertSame(500, $failed->response->status);
        self::assertStringNotContainsString('SENSITIVE_INTERNAL_DETAIL', $failed->response->body);
        self::assertStringNotContainsString('secrets', $failed->response->body);

        $recovered = $this->driver()->dispatch(new WireRequest(), ResponseSpec::json(200, '{"ok":true}'));

        if ($recovered->response instanceof AdapterRejection) {
            self::fail("The adapter rejected the request: {$recovered->response->exceptionClass}: {$recovered->response->message}");
        }

        self::assertNotNull($recovered->observed, 'the same worker must still serve a normal request afterward');
        self::assertSame(200, $recovered->response->status);
    }

    /**
     * The other half of the size-limit defense {@see \Kinetis\RoadRunnerAdapter\RoadRunnerAdapter::assertFormBodyWithinLimit()}'s
     * own docblock discloses it cannot cover: a body with no declared
     * `Content-Length` at all. RoadRunner's own `http.max_request_size`
     * (see {@see RoadRunnerDriver::start()}) is the real defense there —
     * this proves it actually rejects an oversized chunked body rather
     * than merely being documented as if it would. A separate driver
     * instance, configured with a deliberately small limit, so this
     * doesn't affect the shared driver every other test in this class
     * uses.
     */
    public function test_an_oversized_chunked_body_is_rejected_by_road_runners_own_limit(): void
    {
        $driver = RoadRunnerDriver::spawn();
        $driver->start(maxRequestSizeMb: 1);

        try {
            $result = $driver->dispatchOversizedChunkedBody('/', [str_repeat('a', 2_000_000)]);

            self::assertFalse(
                $result['reachedFixture'],
                'a chunked body over the configured http.max_request_size must be rejected before reaching the worker at all',
            );
        } finally {
            $driver->stop();
        }
    }
}
