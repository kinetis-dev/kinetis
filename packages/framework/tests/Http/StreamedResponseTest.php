<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\StreamScopeLease;
use Kinetis\Http\StreamedResponse;
use Kinetis\Runtime\StreamableResponseInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A response whose body is written incrementally by a closure rather than
 * read from a stream — how MCP progress notifications reach the client
 * while a tool is still running.
 *
 * It composes a plain response rather than extending one, so every PSR-7
 * method is hand-written delegation. That is the risk worth testing: a
 * delegation that returns the inner response instead of a new
 * StreamedResponse silently drops the emitter and the lease with it, and
 * the stream simply stops working with nothing to indicate why.
 */
final class StreamedResponseTest extends TestCase
{
    private int $releases = 0;

    private function streamed(
        ?ResponseInterface $inner = null,
        ?\Closure $emitter = null,
        ?StreamScopeLease $lease = null,
    ): StreamedResponse {
        return new StreamedResponse(
            $inner ?? new Response(200, ['X-Kind' => 'stream']),
            $emitter ?? static function (): void {},
            $lease,
        );
    }

    /**
     * A lease over a real RequestScope, so a test asserting on release is
     * asserting on the disposal that actually happens. Resets the counter,
     * so a loop building one per iteration counts that iteration only.
     */
    private function lease(): StreamScopeLease
    {
        $this->releases = 0;
        $app = new AppScope();
        $scope = new RequestScope($app);
        $scope->onDispose(function (): void {
            $this->releases++;
        });

        return new StreamScopeLease($app, $scope, 'GET', '/stream');
    }

    public function test_is_recognisable_to_a_runtime_adapter(): void
    {
        self::assertInstanceOf(StreamableResponseInterface::class, $this->streamed());
    }

    public function test_the_emitter_is_what_writes_the_body(): void
    {
        $written = [];
        $response = $this->streamed(emitter: static function () use (&$written): void {
            $written[] = 'first';
            $written[] = 'second';
        });

        ($response->getEmitter())();

        self::assertSame(['first', 'second'], $written);
    }

    public function test_delegates_status_headers_and_protocol_to_the_composed_response(): void
    {
        $response = $this->streamed(new Response(207, ['X-Kind' => ['stream'], 'X-Other' => ['a', 'b']]));

        self::assertSame(207, $response->getStatusCode());
        self::assertSame('Multi-status', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertTrue($response->hasHeader('X-Kind'));
        self::assertFalse($response->hasHeader('X-Absent'));
        self::assertSame(['stream'], $response->getHeader('X-Kind'));
        self::assertSame('a, b', $response->getHeaderLine('X-Other'));
        self::assertArrayHasKey('X-Kind', $response->getHeaders());
    }

    /**
     * The other half of the settlement contract: what an owner that will
     * never write the body calls instead of the emitter.
     */
    public function test_abandoning_releases_the_lease_and_never_emits(): void
    {
        $emitted = false;
        $response = $this->streamed(
            emitter: static function () use (&$emitted): void {
                $emitted = true;
            },
            lease: $this->lease(),
        );

        $response->abandon();

        self::assertSame(1, $this->releases);
        self::assertFalse($emitted);
    }

    public function test_emitting_the_body_releases_the_lease_after_the_last_byte(): void
    {
        $releasesDuringEmission = null;
        $response = $this->streamed(
            emitter: function () use (&$releasesDuringEmission): void {
                $releasesDuringEmission = $this->releases;
            },
            lease: $this->lease(),
        );

        ($response->getEmitter())();

        self::assertSame(0, $releasesDuringEmission, 'the scope is alive for as long as the emitter is writing');
        self::assertSame(1, $this->releases);
    }

    /**
     * Part of the body is on the wire by the time an emitter fails, so
     * its own failure is the outcome — the release still happens under
     * it, and adds nothing of its own.
     */
    public function test_an_emitter_failure_still_releases_the_lease_and_stays_primary(): void
    {
        $response = $this->streamed(
            emitter: static function (): void {
                throw new RuntimeException('the emitter itself failed');
            },
            lease: $this->lease(),
        );

        try {
            ($response->getEmitter())();
            self::fail('the emitter failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame('the emitter itself failed', $e->getMessage());
        }

        self::assertSame(1, $this->releases);
    }

    /**
     * The settlements are alternatives, not a sequence: the first one to
     * arrive releases, and every later one finds nothing to do.
     */
    public function test_the_lease_is_released_once_however_many_settlements_reach_it(): void
    {
        $response = $this->streamed(lease: $this->lease());

        ($response->getEmitter())();
        $response->abandon();
        ($response->getEmitter())();
        $response->withHeader('X-Late', 'v')->abandon();

        self::assertSame(1, $this->releases);
    }

    /**
     * How the Kernel tells a clone of the wrapper it built — which still
     * owns the request scope, and which an adapter will settle — from a
     * response a middleware put in its place.
     */
    public function test_the_response_and_its_clones_carry_the_lease_they_were_built_with(): void
    {
        $lease = $this->lease();
        $response = $this->streamed(lease: $lease);

        self::assertTrue($response->carries($lease));
        self::assertTrue($response->withHeader('X-New', 'v')->withStatus(206)->carries($lease));
        self::assertFalse($this->streamed(lease: $this->lease())->carries($lease));
        self::assertFalse($this->streamed()->carries($lease));
    }

    /**
     * A response holding nothing — a controller's own stream, whose
     * scope belongs to the Kernel wrapper composing it — has nothing to
     * release, and abandoning it is not an error.
     */
    public function test_abandoning_a_response_that_holds_nothing_does_nothing(): void
    {
        $emitted = false;
        $response = $this->streamed(emitter: static function () use (&$emitted): void {
            $emitted = true;
        });

        $response->abandon();

        self::assertFalse($emitted);
    }

    /**
     * Every `with*` must return a StreamedResponse that still carries the
     * emitter. Returning the inner response would type-check and lose the
     * stream.
     */
    public function test_every_with_method_preserves_the_emitter(): void
    {
        $marker = [];
        $emitter = static function () use (&$marker): void {
            $marker[] = 'emitted';
        };

        $mutations = [
            'withStatus' => static fn (StreamedResponse $r): ResponseInterface => $r->withStatus(500),
            'withProtocolVersion' => static fn (StreamedResponse $r): ResponseInterface => $r->withProtocolVersion('2'),
            'withHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withHeader('X-New', 'v'),
            'withAddedHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withAddedHeader('X-Kind', 'more'),
            'withoutHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withoutHeader('X-Kind'),
            'withBody' => static fn (StreamedResponse $r): ResponseInterface => $r->withBody(Stream::create('ignored')),
        ];

        foreach ($mutations as $name => $mutate) {
            $result = $mutate($this->streamed(emitter: $emitter));

            self::assertInstanceOf(StreamedResponse::class, $result, "{$name}() dropped the streaming response");

            $marker = [];
            ($result->getEmitter())();
            self::assertSame(['emitted'], $marker, "{$name}() lost the emitter");
        }
    }

    /**
     * The emitter's counterpart: a clone that kept the emitter but
     * dropped the lease would leave an adapter that cannot stream with
     * no way to settle the response at all.
     */
    public function test_every_with_method_preserves_the_lease(): void
    {
        $mutations = [
            'withStatus' => static fn (StreamedResponse $r): ResponseInterface => $r->withStatus(500),
            'withProtocolVersion' => static fn (StreamedResponse $r): ResponseInterface => $r->withProtocolVersion('2'),
            'withHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withHeader('X-New', 'v'),
            'withAddedHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withAddedHeader('X-Kind', 'more'),
            'withoutHeader' => static fn (StreamedResponse $r): ResponseInterface => $r->withoutHeader('X-Kind'),
            'withBody' => static fn (StreamedResponse $r): ResponseInterface => $r->withBody(Stream::create('ignored')),
        ];

        foreach ($mutations as $name => $mutate) {
            $result = $mutate($this->streamed(lease: $this->lease()));

            self::assertInstanceOf(StreamableResponseInterface::class, $result, "{$name}() dropped the streaming response");

            $result->abandon();
            self::assertSame(1, $this->releases, "{$name}() lost the lease");
        }
    }

    public function test_the_mutations_actually_apply(): void
    {
        $response = $this->streamed();

        self::assertSame(500, $response->withStatus(500)->getStatusCode());
        self::assertSame('2', $response->withProtocolVersion('2')->getProtocolVersion());
        self::assertSame('v', $response->withHeader('X-New', 'v')->getHeaderLine('X-New'));
        self::assertSame('stream, more', $response->withAddedHeader('X-Kind', 'more')->getHeaderLine('X-Kind'));
        self::assertFalse($response->withoutHeader('X-Kind')->hasHeader('X-Kind'));
    }

    /**
     * getBody() exists to satisfy the interface. It is never what carries
     * the payload — the emitter is — so it stays empty.
     */
    public function test_the_body_is_not_where_the_payload_lives(): void
    {
        self::assertSame('', (string) $this->streamed()->getBody());
    }
}
