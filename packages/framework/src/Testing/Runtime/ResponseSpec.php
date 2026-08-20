<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use Closure;
use Kinetis\Http\StreamedResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * What the handler under a conformance test should answer with — plain
 * data rather than a closure, so a driver whose environment runs the
 * handler in another process (a real `php -S` fixture server for the
 * superglobals adapters) can carry it across the boundary, and the
 * in-process drivers use the identical shape.
 *
 * {@see toResponse()} is the one place a spec becomes a real PSR-7
 * response, shared by every driver: the suite's response-side
 * assertions only mean something if each adapter was handed the same
 * object for the same spec.
 */
final readonly class ResponseSpec
{
    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param list<string> $setCookies each a complete `Set-Cookie` value
     * @param list<string>|null $streamChunks when set, the response is a
     *     {@see StreamedResponse} emitting these in order and $body is
     *     ignored
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public array $setCookies = [],
        public string $body = '',
        public ?array $streamChunks = null,
    ) {}

    public static function json(int $status, string $body): self
    {
        return new self($status, [['Content-Type', 'application/json']], body: $body);
    }

    public function toResponse(): ResponseInterface
    {
        $response = new Response($this->status);

        foreach ($this->headers as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        foreach ($this->setCookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        if ($this->streamChunks !== null) {
            $chunks = $this->streamChunks;

            return new StreamedResponse($response, static function () use ($chunks): void {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                    flush();
                }
            });
        }

        $response->getBody()->write($this->body);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'setCookies' => $this->setCookies,
            'body' => base64_encode($this->body),
            'streamChunks' => $this->streamChunks === null ? null : array_map(base64_encode(...), $this->streamChunks),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{0: string, 1: string}> $headers */
        $headers = $data['headers'];
        /** @var list<string> $setCookies */
        $setCookies = $data['setCookies'];
        /** @var list<string>|null $chunks */
        $chunks = $data['streamChunks'];

        return new self(
            (int) $data['status'],
            $headers,
            $setCookies,
            self::decode((string) $data['body']),
            $chunks === null ? null : array_map(self::decode(...), $chunks),
        );
    }

    private static function decode(string $base64): string
    {
        $decoded = base64_decode($base64, strict: true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * A closure form for drivers whose environment takes a callable
     * handler directly — captures the observed request on the way through.
     *
     * @param Closure(ObservedRequest): void $observe
     */
    public function asHandler(Closure $observe): Closure
    {
        return function (\Psr\Http\Message\ServerRequestInterface $request) use ($observe): ResponseInterface {
            $observe(ObservedRequest::fromServerRequest($request));

            return $this->toResponse();
        };
    }
}
