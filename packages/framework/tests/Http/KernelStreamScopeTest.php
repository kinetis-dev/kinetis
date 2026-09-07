<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\StreamedResponse;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Runtime\StreamableResponseInterface;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Http\Fixtures\StreamDisplacingMiddleware;
use Kinetis\Tests\Http\Fixtures\StreamFailingMiddleware;
use Kinetis\Tests\Http\Fixtures\StreamHeaderMiddleware;
use Kinetis\Tests\Http\Fixtures\StreamingFixtureController;
use Kinetis\Tests\Http\Fixtures\StreamProbe;
use Kinetis\Tests\Http\Fixtures\StreamReplacingMiddleware;
use Kinetis\Tests\Http\Fixtures\StreamShortCircuitMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/Fixtures/gc_collect_cycles_spy.php';

/**
 * A successful StreamableResponseInterface keeps its RequestScope alive
 * through body emission, and Kernel owns the release on every path that
 * can reach it: the emitter's own completion, `abandon()` from an owner
 * that will never write the body, the global pipeline displacing the
 * wrapper — with a buffered response, with a stream of its own, or by
 * failing — the next request, and the wrapper simply being dropped.
 */
final class KernelStreamScopeTest extends TestCase
{
    private InMemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        StreamProbe::reset();
        $this->logger = new InMemoryLogger();
    }

    /**
     * $outermost stands in for SecurityHeadersMiddleware, the one global
     * position outside ExceptionHandlerMiddleware — the only place a
     * middleware failure reaches `handle()`'s caller rather than becoming
     * that middleware's own 500.
     *
     * @param list<class-string<\Psr\Http\Server\MiddlewareInterface>> $globalMiddleware
     * @param class-string<\Psr\Http\Server\MiddlewareInterface>|null $outermost
     */
    private function kernel(bool $isPersistent = false, array $globalMiddleware = [], ?string $outermost = null): Kernel
    {
        $app = new AppScope();
        $app->instance(AppEnvironment::class, AppEnvironment::Production);
        $app->instance(LoggerInterface::class, $this->logger);

        if ($outermost !== null) {
            $app->bind(SecurityHeadersMiddleware::class, $outermost);
        }

        foreach ($globalMiddleware as $middleware) {
            $app->middleware($middleware);
        }

        $app->boot();

        $router = new Router();
        $router->register(StreamingFixtureController::class);

        return new Kernel($app, $router, isPersistent: $isPersistent);
    }

    private function stream(Kernel $kernel, string $path = '/stream', string $tag = 'alpha'): ResponseInterface
    {
        return $kernel->handle(new ServerRequest('GET', $path, ['X-Tag' => $tag]));
    }

    private static function emit(ResponseInterface $response): void
    {
        self::assertInstanceOf(StreamableResponseInterface::class, $response);
        ($response->getEmitter())();
    }

    private static function abandon(?ResponseInterface $response): void
    {
        self::assertInstanceOf(StreamableResponseInterface::class, $response);
        $response->abandon();
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function records(string $level): array
    {
        return array_values(array_filter($this->logger->records, static fn (array $r): bool => $r['level'] === $level));
    }

    /**
     * StreamTagMiddleware registers StreamTagInterface on the request's
     * own scope and nothing else does — it is not autowirable — so the
     * emitter resolving it proves it ran against that same scope rather
     * than a fresh or disconnected one. The dispose hook runs after the
     * last byte, not before the first.
     */
    public function test_a_streamed_emitter_resolves_from_the_scope_a_middleware_populated(): void
    {
        $response = $this->stream($this->kernel(), tag: 'alpha');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(['dispatch:alpha'], StreamProbe::$events, 'nothing is emitted or disposed before the emitter runs');

        self::emit($response);

        self::assertSame(['dispatch:alpha', 'emitted:alpha', 'disposed'], StreamProbe::$events);
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
    }

    public function test_the_wrapper_preserves_the_original_status_headers_and_reason(): void
    {
        $response = $this->stream($this->kernel());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Streaming', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
    }

    /**
     * A middleware setting a header on the returned response builds a
     * clone of the wrapper. That clone carries the same emitter closure,
     * so it carries the same lease — the scope is still released, and
     * released once.
     */
    public function test_a_middleware_header_clone_keeps_the_same_lease(): void
    {
        $response = $this->stream($this->kernel(globalMiddleware: [StreamHeaderMiddleware::class]), tag: 'alpha');

        self::assertSame('yes', $response->getHeaderLine('X-Wrapped'));
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        self::emit($response);

        self::assertSame(['dispatch:alpha', 'emitted:alpha', 'disposed'], StreamProbe::$events);
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
    }

    /**
     * Part of the body is already on the wire, so the emitter's own
     * failure is the outcome: a disposal failure underneath it is logged
     * and discarded rather than replacing it.
     */
    public function test_an_emitter_failure_stays_primary_when_disposal_also_fails(): void
    {
        $response = $this->stream($this->kernel(), '/stream/failing-emitter');

        try {
            self::emit($response);
            self::fail('the emitter failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame('the emitter itself failed', $e->getMessage());
        }

        self::assertTrue(StreamProbe::$scopes[0]->isDisposed(), 'the scope is released even when the emitter failed');

        $errors = $this->records('error');
        self::assertCount(1, $errors);
        self::assertSame('dispose callback failed', $errors[0]['context']['message']);
    }

    /**
     * Nothing is left to turn a disposal failure into: status, headers
     * and body have already been written. It is logged and contained.
     */
    public function test_a_disposal_failure_after_emission_is_logged_and_contained(): void
    {
        $response = $this->stream($this->kernel(), '/stream/failing-disposal');

        self::emit($response);

        self::assertSame(['dispatch:alpha', 'emitted:alpha', 'disposed'], StreamProbe::$events);

        $errors = $this->records('error');
        self::assertCount(1, $errors);
        self::assertSame('dispose callback failed', $errors[0]['context']['message']);
    }

    /**
     * The settlement an adapter that cannot stream performs: the scope
     * is released and the body is never written.
     */
    public function test_abandoning_a_returned_stream_releases_its_scope_without_emitting(): void
    {
        $response = $this->stream($this->kernel(isPersistent: true), tag: 'alpha');

        self::abandon($response);

        self::assertSame(['dispatch:alpha', 'disposed'], StreamProbe::$events);
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertSame(1, StreamProbe::collections(), 'a collection cycle follows the release');
    }

    /**
     * Abandonment settles the request, so the next one has nothing left
     * to clean up and nothing to warn about.
     */
    public function test_an_abandoned_stream_is_not_released_again_by_the_next_request(): void
    {
        $kernel = $this->kernel(isPersistent: true);

        self::abandon($this->stream($kernel, tag: 'one'));
        $this->stream($kernel, tag: 'two');

        self::assertSame(['dispatch:one', 'disposed', 'dispatch:two'], StreamProbe::$events);
        self::assertSame([], $this->records('warning'), 'an abandoned stream is a settled one');
    }

    /**
     * A `with*` clone rebuilds the wrapper around both of its closures,
     * so a middleware that only touched a header hands on a response an
     * adapter can still abandon.
     */
    public function test_a_middleware_header_clone_can_still_be_abandoned(): void
    {
        $response = $this->stream($this->kernel(globalMiddleware: [StreamHeaderMiddleware::class]), tag: 'alpha');

        self::assertSame('yes', $response->getHeaderLine('X-Wrapped'));

        self::abandon($response);

        self::assertSame(['dispatch:alpha', 'disposed'], StreamProbe::$events);
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
    }

    /**
     * The two settlements are alternatives, not a sequence: whichever
     * one arrives first disposes, and the other finds nothing to do.
     */
    public function test_a_stream_is_disposed_once_however_many_settlements_reach_it(): void
    {
        $response = $this->stream($this->kernel(), tag: 'one');

        self::emit($response);
        self::abandon($response);
        self::abandon($response);

        self::assertSame(['dispatch:one', 'emitted:one', 'disposed'], StreamProbe::$events);
    }

    /**
     * Global middleware can answer with a buffered response of its own
     * instead of the wrapper dispatch produced. Nothing downstream will
     * ever emit that wrapper, so Kernel settles it on this request
     * rather than leaving it for the next one.
     */
    public function test_a_middleware_replacing_the_stream_releases_its_scope_before_handle_returns(): void
    {
        $kernel = $this->kernel(isPersistent: true, globalMiddleware: [StreamReplacingMiddleware::class]);

        $response = $kernel->handle(new ServerRequest('GET', '/stream', [
            'X-Tag' => 'one',
            StreamReplacingMiddleware::HEADER => 'yes',
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertNotInstanceOf(StreamableResponseInterface::class, $response);
        self::assertSame(
            ['dispatch:one', 'replaced', 'disposed'],
            StreamProbe::$events,
            'the replaced stream is released before handle() hands the buffered response back',
        );
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertSame([], $this->records('warning'), 'a replaced stream is settled, not abandoned by omission');
        self::assertSame(1, StreamProbe::collections());
    }

    /**
     * Global middleware can answer with a stream of its own. The final
     * response streams, but it is not the wrapper this request produced:
     * the replacement stays emittable, and the wrapper it displaced is
     * released before handle() returns.
     */
    public function test_a_middleware_replacing_the_stream_with_another_stream_releases_only_the_displaced_scope(): void
    {
        $kernel = $this->kernel(isPersistent: true, globalMiddleware: [StreamDisplacingMiddleware::class]);

        $response = $kernel->handle(new ServerRequest('GET', '/stream', [
            'X-Tag' => 'one',
            StreamDisplacingMiddleware::HEADER => 'yes',
        ]));

        self::assertInstanceOf(StreamableResponseInterface::class, $response);
        self::assertSame(203, $response->getStatusCode());
        self::assertSame(
            ['dispatch:one', 'displaced', 'disposed'],
            StreamProbe::$events,
            'the displaced wrapper is released before handle() hands the replacement back',
        );
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertSame([], $this->records('warning'), 'a displaced stream is settled, not abandoned by omission');
        self::assertSame(1, StreamProbe::collections());

        self::emit($response);

        self::assertSame(
            ['dispatch:one', 'displaced', 'disposed', 'replacement-emitted'],
            StreamProbe::$events,
            'the replacement is still the response an adapter emits',
        );

        self::abandon(StreamProbe::$displaced);

        self::assertSame(
            ['dispatch:one', 'displaced', 'disposed', 'replacement-emitted'],
            StreamProbe::$events,
            'settling the displaced wrapper afterwards releases nothing further',
        );
        self::assertSame(1, StreamProbe::collections());
    }

    /**
     * A middleware that takes delivery of the stream and then fails
     * leaves handle() exceptionally, so nothing downstream will ever
     * settle the wrapper. Its scope is released before the exception
     * escapes, and the request that follows finds nothing left over.
     */
    public function test_a_middleware_failing_after_the_stream_releases_its_scope_before_handle_throws(): void
    {
        $kernel = $this->kernel(isPersistent: true, outermost: StreamFailingMiddleware::class);

        try {
            $kernel->handle(new ServerRequest('GET', '/stream', [
                'X-Tag' => 'one',
                StreamFailingMiddleware::HEADER => 'yes',
            ]));
            self::fail('the middleware failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame(StreamFailingMiddleware::MESSAGE, $e->getMessage());
            self::assertTrue(
                StreamProbe::$scopes[0]->isDisposed(),
                'the scope is released before the exception leaves handle()',
            );
        }

        self::assertSame(['dispatch:one', 'disposed'], StreamProbe::$events);
        self::assertSame(1, StreamProbe::collections());

        self::abandon(StreamProbe::$displaced);
        $this->stream($kernel, tag: 'two');

        self::assertSame(['dispatch:one', 'disposed', 'dispatch:two'], StreamProbe::$events);
        self::assertSame(1, StreamProbe::collections(), 'the failed request released its scope once');
        self::assertSame([], $this->records('warning'), 'the failing request settled its own stream');
    }

    /**
     * A wrapper stays abandonable after the request that produced it has
     * been replaced by later ones, and abandoning it then must settle
     * only its own scope — never the lease the current request is
     * holding.
     */
    public function test_abandoning_a_stream_from_an_earlier_request_leaves_the_current_one_pending(): void
    {
        $kernel = $this->kernel(isPersistent: true);

        $first = $this->stream($kernel, tag: 'one');
        $this->stream($kernel, tag: 'two');

        self::abandon($first);
        self::assertSame(['dispatch:one', 'disposed', 'dispatch:two'], StreamProbe::$events);
        self::assertFalse(StreamProbe::$scopes[1]->isDisposed(), 'the current request keeps its own scope');

        $this->stream($kernel, tag: 'three');

        self::assertTrue(StreamProbe::$scopes[1]->isDisposed(), 'and the next request still releases it');
        self::assertCount(2, $this->records('warning'));
    }

    /**
     * The defensive path, for a wrapper an owner settled neither way: a
     * caller that reads its status and drops it, an exception trace
     * pinning it as a frame argument, an adapter that refuses a stream
     * without abandoning it. The next request releases it before it has
     * a scope at all.
     */
    public function test_a_stream_that_is_settled_neither_way_is_released_by_the_next_request(): void
    {
        $kernel = $this->kernel(isPersistent: true);

        $retained = $this->stream($kernel, tag: 'one');
        self::assertSame(['dispatch:one'], StreamProbe::$events);

        $this->stream($kernel, tag: 'two');

        self::assertSame(
            ['dispatch:one', 'disposed', 'dispatch:two'],
            StreamProbe::$events,
            'the unsettled scope is released before the next request reaches a controller',
        );
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertFalse(StreamProbe::$scopes[1]->isDisposed());
        self::assertNotSame(StreamProbe::$scopes[0], StreamProbe::$scopes[1]);

        $warnings = $this->records('warning');
        self::assertCount(1, $warnings);
        self::assertSame('GET', $warnings[0]['context']['method']);
        self::assertSame('/stream', $warnings[0]['context']['path']);

        self::assertInstanceOf(StreamableResponseInterface::class, $retained);
    }

    /**
     * Global middleware runs before any of dispatch does, and shipped
     * middleware can answer a request without ever calling its handler.
     * The release has to sit ahead of that pipeline, or a prior stream's
     * scope survives every short-circuited request that follows.
     */
    public function test_a_short_circuiting_global_middleware_runs_after_the_prior_stream_is_released(): void
    {
        $kernel = $this->kernel(isPersistent: true, globalMiddleware: [StreamShortCircuitMiddleware::class]);

        $retained = $this->stream($kernel, tag: 'one');
        self::assertSame(['dispatch:one'], StreamProbe::$events);

        $response = $kernel->handle(new ServerRequest('GET', '/stream', [
            'X-Tag' => 'two',
            StreamShortCircuitMiddleware::HEADER => 'yes',
        ]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            ['dispatch:one', 'disposed', 'short-circuit'],
            StreamProbe::$events,
            'the prior scope is released before the next request reaches its first global middleware',
        );
        self::assertCount(1, StreamProbe::$scopes, '/stream is routable, and the short-circuited request never reached it');
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertCount(1, $this->records('warning'));

        self::assertInstanceOf(StreamableResponseInterface::class, $retained);
    }

    /**
     * Two streamed requests through one persistent Kernel each emit
     * against their own scope, so a value one request registered can
     * never be what the next request's emitter resolves.
     */
    public function test_two_persistent_requests_do_not_share_deferred_request_scoped_state(): void
    {
        $kernel = $this->kernel(isPersistent: true);

        $first = $this->stream($kernel, tag: 'one');
        self::emit($first);

        $second = $this->stream($kernel, tag: 'two');
        self::emit($second);

        self::assertSame(
            ['dispatch:one', 'emitted:one', 'disposed', 'dispatch:two', 'emitted:two', 'disposed'],
            StreamProbe::$events,
        );
        self::assertNotSame(StreamProbe::$scopes[0], StreamProbe::$scopes[1]);
        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertTrue(StreamProbe::$scopes[1]->isDisposed());
        self::assertSame([], $this->records('warning'), 'an emitted stream is not an abandoned one');
    }

    /**
     * The deferred counterpart of the collection cycle an ordinary
     * disposal already forces: it runs once the scope is released, not
     * when the response is returned.
     */
    public function test_a_collection_cycle_follows_a_deferred_release_when_persistent(): void
    {
        $response = $this->stream($this->kernel(isPersistent: true));

        self::assertSame(0, StreamProbe::collections(), 'nothing is released yet, so nothing is collected yet');

        self::emit($response);

        self::assertSame(0, StreamProbe::$collectionsAtDisposal, 'collection follows disposal');
        self::assertSame(1, StreamProbe::collections());
    }

    public function test_no_collection_cycle_follows_a_deferred_release_when_not_persistent(): void
    {
        $response = $this->stream($this->kernel());

        self::emit($response);

        self::assertTrue(StreamProbe::$scopes[0]->isDisposed());
        self::assertSame(0, StreamProbe::collections());
    }
}
