<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Form\Exception\FormStagingException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\MaxBodySizeMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Form\FailingStream;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The byte ceiling, settled before the handler runs rather than while it
 * reads. What that buys, and what a counting stream wrapper cannot give,
 * is that every way of reading the accepted body returns the same bytes
 * — the `(string)` cast included, which such a wrapper has to answer
 * with an empty string.
 */
final class MaxBodySizeMiddlewareTest extends TestCase
{
    private function middleware(int $maxBytes): MaxBodySizeMiddleware
    {
        return new MaxBodySizeMiddleware(new FormLimits($maxBytes));
    }

    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    public function test_a_non_positive_max_body_size_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAX_BODY_SIZE must be a positive number of bytes');

        FormLimits::fromConfig(new Config(['MAX_BODY_SIZE' => '0']));
    }

    public function test_a_request_under_the_limit_passes_through(): void
    {
        $response = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => '500'], body: str_repeat('x', 500)),
            $this->handler(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A body exactly on the ceiling is accepted and one byte over is
     * not — the two cases a `>` and a `>=` disagree about, and the only
     * pair that pins which one this is.
     */
    public function test_the_ceiling_itself_is_accepted_and_one_byte_over_is_not(): void
    {
        $atLimit = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: str_repeat('x', 1_000)),
            $this->handler(),
        );
        $overLimit = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: str_repeat('x', 1_001)),
            $this->handler(),
        );

        self::assertSame(200, $atLimit->getStatusCode());
        self::assertSame(413, $overLimit->getStatusCode());
    }

    public function test_a_request_declaring_more_than_the_limit_is_rejected_with_413(): void
    {
        $response = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => '1001']),
            $this->handler(),
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true));
    }

    public function test_the_handler_never_runs_once_the_limit_is_exceeded(): void
    {
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });

        $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: str_repeat('x', 2_000)),
            $handler,
        );

        self::assertSame(0, $calls);
    }

    /**
     * The declared-header check alone cannot catch either of these: a
     * missing `Content-Length` skips it, and a dishonest one passes it.
     * The staged byte count is what bounds both.
     */
    public function test_an_oversized_body_is_rejected_whatever_its_length_claimed(): void
    {
        $undeclared = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: str_repeat('x', 1_500)),
            $this->handler(),
        );
        $understated = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => '10'], body: str_repeat('x', 1_500)),
            $this->handler(),
        );

        self::assertSame(413, $undeclared->getStatusCode());
        self::assertSame(413, $understated->getStatusCode());
    }

    /**
     * The failure a counting stream wrapper cannot prevent. `Stringable`
     * forbids `__toString()` from throwing, so a wrapper has to answer a
     * cast with an empty string once its cap is crossed — and a handler,
     * or any vendor middleware between the wrapper and it, reads that as
     * an absent optional body and carries on. Settling the ceiling in
     * front of the handler is what makes the case unreachable: the
     * request never arrives.
     */
    public function test_a_handler_that_only_casts_the_body_cannot_see_an_oversized_request_as_an_empty_one(): void
    {
        $seen = null;
        $handler = new CallableRequestHandler(function (ServerRequestInterface $request) use (&$seen) {
            $seen = (string) $request->getBody();

            return new Response(200);
        });

        $response = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: str_repeat('x', 1_500)),
            $handler,
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertNull($seen, 'the handler must not have run at all');
    }

    /**
     * Every way of reading the accepted body agrees, and each one can be
     * used after the others — the staged stream is seekable, so nothing
     * downstream has to know whether something already read it.
     */
    public function test_read_get_contents_and_a_string_cast_all_return_the_identical_accepted_body(): void
    {
        $body = str_repeat('payload ', 100);
        $seen = [];
        $handler = new CallableRequestHandler(function (ServerRequestInterface $request) use (&$seen) {
            $stream = $request->getBody();

            $seen['cast'] = (string) $stream;
            $stream->rewind();
            $seen['contents'] = $stream->getContents();
            $stream->rewind();

            $read = '';

            while (!$stream->eof()) {
                $read .= $stream->read(64);
            }

            $seen['read'] = $read;
            $seen['size'] = $stream->getSize();
            $seen['seekable'] = $stream->isSeekable();

            return new Response(200);
        });

        $response = $this->middleware(10_000)->process(new ServerRequest('POST', '/', body: $body), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, $seen['cast']);
        self::assertSame($body, $seen['contents']);
        self::assertSame($body, $seen['read']);
        self::assertSame(strlen($body), $seen['size']);
        self::assertTrue($seen['seekable']);
    }

    /**
     * A body that arrives on a pipe reports no size, cannot be rewound,
     * and can be read once — the ordinary shape of a real request body
     * under a SAPI. It has to stage exactly like any other.
     */
    public function test_a_non_seekable_incremental_body_is_staged_whole(): void
    {
        $body = str_repeat('chunk', 5_000);
        [$read, $write] = self::pipe();
        fwrite($write, $body);
        fclose($write);

        $seen = null;
        $handler = new CallableRequestHandler(function (ServerRequestInterface $request) use (&$seen) {
            $seen = (string) $request->getBody();

            return new Response(200);
        });

        $source = Stream::create($read);
        self::assertFalse($source->isSeekable(), 'a pipe is the case this test exists for');

        $response = $this->middleware(100_000)->process(
            new ServerRequest('POST', '/', body: $source),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, $seen);
    }

    public function test_a_non_seekable_body_past_the_ceiling_is_still_refused(): void
    {
        [$read, $write] = self::pipe();
        fwrite($write, str_repeat('x', 5_000));
        fclose($write);

        $response = $this->middleware(1_000)->process(
            new ServerRequest('POST', '/', body: Stream::create($read)),
            $this->handler(),
        );

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * A temporary stream that stops accepting bytes is this worker's
     * failure, not the client's — so it is neither a `413` nor a `400`,
     * and the handle is closed on the way out rather than leaked.
     */
    public function test_a_staging_write_failure_is_an_infrastructure_failure_and_still_closes_the_handle(): void
    {
        FailingStream::register();
        FailingStream::reset();
        FailingStream::$chunkSize = 8;
        FailingStream::$refuseAfter = 32;

        try {
            $this->expectException(FormStagingException::class);

            \Kinetis\Http\Form\StagedRequestBody::stage(
                Stream::create(str_repeat('x', 1_000)),
                new FormLimits(10_000),
                null,
                FailingStream::open(...),
            );
        } finally {
            self::assertTrue(FailingStream::$closed, 'the staging handle is closed even when the write failed');
            FailingStream::reset();
            stream_wrapper_unregister(FailingStream::SCHEME);
        }
    }

    /**
     * The two things an empty read can mean, and the pair that keeps them
     * apart. A stream reports "not at the end" until a read has hit the
     * end, so an empty body's first read comes back empty from a stream
     * that then says it is done — and stages as the empty body it is. A
     * stream that comes back empty and still says there is more will
     * never yield anything, so it is refused rather than read forever.
     */
    public function test_an_empty_body_stages_whole_while_a_stream_that_stops_yielding_is_refused(): void
    {
        $seen = null;
        $handler = new CallableRequestHandler(function (ServerRequestInterface $request) use (&$seen) {
            $seen = (string) $request->getBody();

            return new Response(200);
        });

        $response = $this->middleware(1_000)->process(new ServerRequest('POST', '/', body: ''), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $seen);

        FailingStream::register();
        FailingStream::reset();
        FailingStream::$stallReads = true;

        try {
            $this->expectException(FormStagingException::class);
            $this->expectExceptionMessage('stopped yielding bytes after 0 of them');

            \Kinetis\Http\Form\StagedRequestBody::stage(
                Stream::create(FailingStream::open()),
                new FormLimits(10_000),
                null,
            );
        } finally {
            FailingStream::reset();
            stream_wrapper_unregister(FailingStream::SCHEME);
        }
    }

    public function test_a_temporary_stream_that_cannot_be_opened_is_an_infrastructure_failure(): void
    {
        $this->expectException(FormStagingException::class);

        \Kinetis\Http\Form\StagedRequestBody::stage(
            Stream::create('body'),
            new FormLimits(10_000),
            null,
            static fn (): bool => false,
        );
    }

    public function test_defaults_to_two_mebibytes_when_unconfigured(): void
    {
        $middleware = new MaxBodySizeMiddleware(FormLimits::fromConfig(new Config([])));

        $underDefault = $middleware->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => (string) FormLimits::DEFAULT_MAX_BODY_BYTES]),
            $this->handler(),
        );
        $overDefault = $middleware->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => (string) (FormLimits::DEFAULT_MAX_BODY_BYTES + 1)]),
            $this->handler(),
        );

        self::assertSame(200, $underDefault->getStatusCode());
        self::assertSame(413, $overDefault->getStatusCode());
    }

    public function test_runs_unconditionally_as_global_middleware_right_after_the_exception_handler(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['MAX_BODY_SIZE' => '1000']));
        $app->boot();

        $kernel = new Kernel($app, new Router());

        $response = $kernel->handle(new ServerRequest('POST', '/nonexistent', headers: ['Content-Length' => '1001']));

        // 413 rather than the 404 an unregistered route would otherwise
        // produce — proving this runs before routing, with no explicit
        // registration needed.
        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private static function pipe(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
