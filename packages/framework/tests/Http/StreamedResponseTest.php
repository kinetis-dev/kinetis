<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Http\StreamedResponse;
use Kinetis\Runtime\StreamableResponseInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A response whose body is written incrementally by a closure rather than
 * read from a stream — how MCP progress notifications reach the client
 * while a tool is still running.
 *
 * It composes a plain response rather than extending one, so every PSR-7
 * method is hand-written delegation. That is the risk worth testing: a
 * delegation that returns the inner response instead of a new
 * StreamedResponse silently drops the emitter, and the stream simply
 * stops working with nothing to indicate why.
 */
final class StreamedResponseTest extends TestCase
{
    private function streamed(?ResponseInterface $inner = null, ?\Closure $emitter = null): StreamedResponse
    {
        return new StreamedResponse(
            $inner ?? new Response(200, ['X-Kind' => 'stream']),
            $emitter ?? static function (): void {},
        );
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
