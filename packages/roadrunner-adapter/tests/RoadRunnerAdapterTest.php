<?php

declare(strict_types=1);

namespace Kinetis\RoadRunnerAdapter\Tests;

use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\StreamedResponse;
use Kinetis\RoadRunnerAdapter\RoadRunnerAdapter;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What only `RoadRunnerAdapter::handle()` itself does — form-body
 * parsing, header folding, the streaming refusal — proven directly
 * against a fabricated `ServerRequestInterface`, with no real `rr`
 * binary in the loop. Everything shared with the other adapters
 * (request/response shape, cookies, the malformed-body 400 contract)
 * lives in the runtime conformance suite instead, run against this
 * adapter by {@see RoadRunnerConformanceTest} — this class holds only
 * what a fabricated request can exercise and the shared suite can't.
 */
final class RoadRunnerAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new RoadRunnerAdapter())->isPersistent());
    }

    /**
     * PSR7Worker's own header mapping presents a repeated header as
     * several separate array values, not the single comma-joined value
     * RFC 9110 makes equivalent — this is the fold that closes that gap,
     * proven directly rather than only through the real-binary
     * conformance suite.
     *
     * Asserted via `getHeader()` (the raw stored value list), not
     * `getHeaderLine()` — the latter already joins on `,` internally
     * regardless of storage, so a test reading it back can't actually
     * tell "the fold happened" apart from "nothing happened and
     * getHeaderLine() did its own joining anyway." Caught by Infection,
     * not by review: the first version of this test used
     * `getHeaderLine()` and kept passing with `foldRepeatedHeaders()`'s
     * whole `foreach` loop removed.
     */
    public function test_folds_a_repeated_header_into_one_comma_joined_value(): void
    {
        $request = new ServerRequest('GET', '/', ['X-Trace' => ['first', 'second']]);

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['first, second'], $captured->getHeader('X-Trace'));
    }

    /**
     * The other half of the same distinction {@see test_folds_a_repeated_header_into_one_comma_joined_value()}
     * makes: a single value must reach the handler as exactly one
     * stored value, not "coincidentally still one value because
     * implode() of a one-element array equals that element" — the
     * latter is indistinguishable from the former via `getHeaderLine()`
     * alone, which is why this asserts `getHeader()` too.
     */
    public function test_a_single_valued_header_is_left_untouched(): void
    {
        $request = new ServerRequest('GET', '/', ['X-Trace' => 'only']);

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['only'], $captured->getHeader('X-Trace'));
    }

    /**
     * The file field is deliberately placed *before* the text field —
     * catching Infection with the reverse order, the file field last:
     * `continue` (skip only the "also store this as a text field" line)
     * and `break` (stop the loop entirely) produce the identical result
     * when the file field happens to be the final part, since there's
     * nothing left to process either way. Putting a real field after it
     * is what makes the two observably different.
     */
    public function test_parses_a_multipart_body_into_parsed_body_and_uploaded_files(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "\xFFPNGDATA\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $request = new ServerRequest(
            'POST',
            '/avatars',
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertNotNull($captured);
        self::assertSame(['name' => 'Alon'], $captured->getParsedBody());
        self::assertArrayHasKey('avatar', $captured->getUploadedFiles());
        self::assertSame('a.png', $captured->getUploadedFiles()['avatar']->getClientFilename());
    }

    public function test_parses_a_url_encoded_body(): void
    {
        $request = new ServerRequest(
            'POST',
            '/search',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'q=kinetis&page=2',
        );

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(['q' => 'kinetis', 'page' => '2'], $captured->getParsedBody());
    }

    public function test_a_multipart_body_with_no_usable_boundary_is_a_clean_400_and_the_handler_never_runs(): void
    {
        $request = new ServerRequest(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=----XYZ'],
            'not a real multipart body at all',
        );

        $handlerRan = false;
        $response = RoadRunnerAdapter::handle($request, static function () use (&$handlerRan): Response {
            $handlerRan = true;

            return new Response(200);
        });

        self::assertFalse($handlerRan);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode((string) $response->getBody(), true));
    }

    public function test_a_non_form_content_type_is_left_untouched(): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"a":1}');

        $captured = null;
        RoadRunnerAdapter::handle($request, static function (ServerRequestInterface $r) use (&$captured): Response {
            $captured = $r;

            return new Response(200);
        });

        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertNull($captured->getParsedBody());
        self::assertSame('{"a":1}', (string) $captured->getBody());
    }

    /**
     * The exact status/body pairing {@see \Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver}
     * recognizes to report an `AdapterRejection` instead of a successful
     * response — see `RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE`'s
     * own docblock for why this is the one place that pairing is defined.
     */
    public function test_a_streaming_response_is_refused_as_a_real_501_after_the_handler_runs(): void
    {
        $handlerRan = false;
        $streamed = new StreamedResponse(new Response(200), static function () {});

        $response = RoadRunnerAdapter::handle(
            new ServerRequest('GET', '/'),
            static function () use (&$handlerRan, $streamed) {
                $handlerRan = true;

                return $streamed;
            },
        );

        self::assertTrue($handlerRan, 'the refusal must happen after the handler runs, not before');
        self::assertSame(501, $response->getStatusCode());
        self::assertSame(
            ['error' => RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE],
            json_decode((string) $response->getBody(), true),
        );
        self::assertEquals(
            ErrorResponse::create(501, RoadRunnerAdapter::STREAMING_NOT_SUPPORTED_MESSAGE)->getBody()->__toString(),
            (string) $response->getBody(),
        );
    }
}
