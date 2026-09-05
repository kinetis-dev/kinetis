<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Conformance;

use Kinetis\Testing\Runtime\AdapterRejection;
use Kinetis\Testing\Runtime\ResponseSpec;
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Kinetis\Testing\Runtime\WireRequest;

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

    /**
     * The capability this environment's whole form contract rests on,
     * proven against a real server configured the wrong way. With
     * `enable_post_data_reading` on, PHP reads and parses the body
     * before any Kinetis code exists — so `php://input` is empty for a
     * POST form and whatever Kinetis went on to parse would not be the
     * client's bytes. Refused rather than degraded, and refused before
     * the handler.
     *
     * A separate server, so this deliberate misconfiguration cannot
     * affect the correctly-configured one every other case here uses —
     * the same shape `RoadRunnerConformanceTest` uses for `raw_body`.
     */
    public function test_a_sapi_that_reads_the_body_itself_is_refused_rather_than_silently_parsing_the_wrong_bytes(): void
    {
        $driver = SuperglobalsDriver::spawn(postDataReading: true);
        // Not waiting for readiness: the refusal is unconditional, so
        // this server never answers /__conformance/ready either — which
        // is itself the point.
        $driver->start(waitForReady: false);

        try {
            $outcome = $driver->dispatch(
                new WireRequest('POST', '/forms', headers: [['Content-Type', 'application/x-www-form-urlencoded']], body: 'name=Alon'),
                ResponseSpec::json(200, '{"ok":true}'),
            );

            self::assertNull($outcome->observed, 'a misconfigured SAPI must be caught before the handler ever runs');
            self::assertNotInstanceOf(AdapterRejection::class, $outcome->response);
            // The application's own response never reaches the client:
            // the refusal propagates as an uncaught server-side failure,
            // which is what a deployment problem is. The status a SAPI
            // then renders is its own business — the CLI server here
            // answers 200 with a fatal-error page under its default
            // display_errors, where a production image (display_errors
            // off, as php.ini-production sets it) answers 500 — so what
            // is asserted is the part this framework decides.
            self::assertNotSame('{"ok":true}', $outcome->response->body, 'the handler\'s response must never be emitted');
        } finally {
            $driver->stop();
        }
    }

    /**
     * A runtime configured below the contract, proven against a real
     * `parse_str()` rather than argued about. With `max_input_vars` at 8,
     * PHP registers the first eight pairs of a twelve-pair form and drops
     * the rest — a form that is complete to every check run on the
     * result, and missing exactly the fields whichever ones came last.
     * The form is refused before it is parsed instead, naming the
     * setting.
     *
     * A separate server, for the same reason the
     * `enable_post_data_reading` case needs one: the misconfiguration is
     * the thing under test, so it must not touch the correctly
     * configured server every other case here runs against.
     */
    public function test_a_runtime_configured_below_the_contract_refuses_the_form_it_would_have_truncated(): void
    {
        $driver = SuperglobalsDriver::spawn(maxInputVars: 8);
        $driver->start();

        try {
            $pairs = [];

            for ($i = 0; $i < 12; $i++) {
                $pairs[] = "field{$i}=value";
            }

            $outcome = $driver->dispatch(
                new WireRequest(
                    'POST',
                    '/forms',
                    headers: [['Content-Type', 'application/x-www-form-urlencoded']],
                    body: implode('&', $pairs),
                ),
                ResponseSpec::json(200, '{"ok":true}'),
            );

            self::assertNull($outcome->observed, 'the handler must not run for a form this runtime would have shortened');
            self::assertNotInstanceOf(AdapterRejection::class, $outcome->response);
            self::assertOverLimitFormResponse($outcome->response);
            self::assertStringContainsString('max_input_vars', $outcome->response->body, 'the refusal names the setting an operator can fix');
        } finally {
            $driver->stop();
        }
    }

    /**
     * A runtime that would split a form body somewhere other than `&`,
     * proven the same way. `parse_str()` splits on `arg_separator.input`,
     * and everything that counts a body here splits on `&`, so a server
     * naming `&;` reads twelve fields out of the body below while every
     * count that ran read it as one pair — a form measured under one
     * separator and parsed under another. The separator is refused rather
     * than parsed under, so the handler never sees either reading of it.
     *
     * A separate server, for the reason the other two misconfiguration
     * cases need one: the setting is `PHP_INI_PERDIR`, so the only way
     * to have another value is to start a process with it.
     */
    public function test_a_runtime_that_splits_a_form_body_elsewhere_refuses_it_rather_than_reshaping_it(): void
    {
        $driver = SuperglobalsDriver::spawn(argSeparatorInput: '&;');
        $driver->start();

        try {
            $pairs = [];

            for ($i = 0; $i < 12; $i++) {
                $pairs[] = "field{$i}=value";
            }

            $outcome = $driver->dispatch(
                new WireRequest(
                    'POST',
                    '/forms',
                    headers: [['Content-Type', 'application/x-www-form-urlencoded']],
                    body: implode(';', $pairs),
                ),
                ResponseSpec::json(200, '{"ok":true}'),
            );

            self::assertNull($outcome->observed, 'the handler must not run for a form this runtime would have read another way');
            self::assertNotInstanceOf(AdapterRejection::class, $outcome->response);
            // A deployment problem, so it propagates as a server-side
            // failure rather than becoming a 400 or a 413 — the same
            // shape the enable_post_data_reading case has, and for the
            // same reason: the status a SAPI renders for an uncaught
            // failure is its own business, and what this framework
            // decides is that the handler's answer is not it.
            self::assertNotSame('{"ok":true}', $outcome->response->body, 'the handler\'s response must never be emitted');
        } finally {
            $driver->stop();
        }
    }

    /**
     * The forwarded-scheme rule from the other side of the policy. The
     * shared suite proves this driver's own server honors the header
     * because its client is a configured edge; this proves a server that
     * trusts nobody ignores the identical header from the identical
     * client — which is what a directly reachable listener has to do,
     * since there the header is the client's own to set.
     */
    public function test_a_forwarded_scheme_from_an_untrusted_client_is_ignored(): void
    {
        $driver = SuperglobalsDriver::spawn(trustedProxies: '');
        $driver->start();

        try {
            $outcome = $driver->dispatch(
                new WireRequest(headers: [['Host', 'conformance.example'], ['X-Forwarded-Proto', 'https']]),
                ResponseSpec::json(200, '{"ok":true}'),
            );

            self::assertNotInstanceOf(AdapterRejection::class, $outcome->response);
            self::assertNotNull($outcome->observed, 'the request itself is fine — only the header is disbelieved');
            self::assertSame('http', $outcome->observed->scheme, 'a client cannot promote its own request to https');
        } finally {
            $driver->stop();
        }
    }

    /**
     * And a trusted edge that names something unreadable: refused with
     * the same fixed `400` a body that cannot be parsed gets, rather
     * than guessed at or ignored, which would leave the request running
     * under a scheme nothing chose.
     */
    public function test_an_unreadable_forwarded_scheme_from_a_trusted_edge_is_a_clean_400(): void
    {
        $outcome = $this->driver()->dispatch(
            new WireRequest(headers: [['Host', 'conformance.example'], ['X-Forwarded-Proto', 'https, http']]),
            ResponseSpec::json(200, '{"ok":true}'),
        );

        self::assertNull($outcome->observed, 'the handler must not run for an identity that cannot be settled');
        self::assertNotInstanceOf(AdapterRejection::class, $outcome->response);
        self::assertMalformedBodyResponse($outcome->response);
    }
}
