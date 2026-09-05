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
     * @param int $streamDelayMs a pause between chunks, so whether the
     *     environment delivered them as they were written — or held the
     *     whole body back until the end — shows up as elapsed time on the
     *     receiving side
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $setCookies,
        public string $body,
        public ?array $streamChunks,
        public int $streamDelayMs,
    ) {}

    /**
     * A plain, non-streaming response — every field named, none defaulted.
     * The constructor takes all six because a spec is compared field by
     * field across a process boundary: a default is a value the test did
     * not write and the fixture cannot know was absent, and
     * {@see fromArray()} would then have nothing to be strict about.
     *
     * @param list<array{0: string, 1: string}> $headers
     * @param list<string> $setCookies
     */
    public static function of(int $status, array $headers = [], array $setCookies = [], string $body = ''): self
    {
        return new self($status, $headers, $setCookies, $body, null, 0);
    }

    /**
     * A streaming response: $chunks written in order, $delayMs apart, so
     * whether the environment delivered them as they were written shows
     * up as elapsed time on the receiving side.
     *
     * @param list<array{0: string, 1: string}> $headers
     * @param list<string> $chunks
     */
    public static function streaming(int $status, array $headers, array $chunks, int $delayMs): self
    {
        return new self($status, $headers, [], '', $chunks, $delayMs);
    }

    public static function json(int $status, string $body): self
    {
        return self::of($status, [['Content-Type', 'application/json']], body: $body);
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
            $delayMs = $this->streamDelayMs;

            return new StreamedResponse($response, static function () use ($chunks, $delayMs): void {
                // flush() pushes the SAPI's buffer, not PHP's own output
                // buffers — under an output_buffering ini a chunk would
                // sit in one of those until the script ended. Close them
                // first, the way any real streaming emitter has to.
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                foreach ($chunks as $index => $chunk) {
                    if ($index > 0 && $delayMs > 0) {
                        usleep($delayMs * 1_000);
                    }

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
            'streamDelayMs' => $this->streamDelayMs,
        ];
    }

    /**
     * The exact inverse of {@see toArray()}: every field is required and
     * every type is checked. A spec crosses a process boundary as a
     * header on a real HTTP request, so a field that arrived missing,
     * misspelled or mistyped means the two sides of that boundary
     * disagree — and a default filled in here would hide it by turning a
     * disagreement into a quietly different response the suite would
     * then assert against. Invalid base64 is refused for the same
     * reason: decoded as empty bytes it is indistinguishable from a
     * response with no body at all.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // array_key_exists, not ??: streamChunks is legitimately null
        // for a non-streaming spec, and ?? cannot tell that apart from
        // a field that never crossed the boundary at all.
        $chunks = array_key_exists('streamChunks', $data)
            ? $data['streamChunks']
            : throw MalformedResponseSpecException::missingField('streamChunks');

        if ($chunks !== null && !is_array($chunks)) {
            throw MalformedResponseSpecException::wrongType('streamChunks', 'a list of strings or null');
        }

        return new self(
            self::requireInt($data, 'status'),
            self::requirePairs($data, 'headers'),
            self::requireStrings($data, 'setCookies'),
            self::decode(self::requireString($data, 'body')),
            $chunks === null ? null : array_map(self::decode(...), self::strings($chunks, 'streamChunks')),
            self::requireInt($data, 'streamDelayMs'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireInt(array $data, string $field): int
    {
        $value = self::field($data, $field);

        return is_int($value) ? $value : throw MalformedResponseSpecException::wrongType($field, 'an integer');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = self::field($data, $field);

        return is_string($value) ? $value : throw MalformedResponseSpecException::wrongType($field, 'a string');
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function requireStrings(array $data, string $field): array
    {
        return self::strings(self::field($data, $field), $field);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || array_any($value, static fn (mixed $entry): bool => !is_string($entry))) {
            throw MalformedResponseSpecException::wrongType($field, 'a list of strings');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{0: string, 1: string}>
     */
    private static function requirePairs(array $data, string $field): array
    {
        $value = self::field($data, $field);

        if (!is_array($value) || !array_is_list($value)) {
            throw MalformedResponseSpecException::wrongType($field, 'a list of name/value pairs');
        }

        $pairs = [];

        foreach ($value as $pair) {
            if (!is_array($pair) || array_keys($pair) !== [0, 1] || !is_string($pair[0]) || !is_string($pair[1])) {
                throw MalformedResponseSpecException::wrongType($field, 'a list of name/value pairs');
            }

            $pairs[] = [$pair[0], $pair[1]];
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function field(array $data, string $field): mixed
    {
        return array_key_exists($field, $data) ? $data[$field] : throw MalformedResponseSpecException::missingField($field);
    }

    private static function decode(string $base64): string
    {
        $decoded = base64_decode($base64, strict: true);

        return $decoded === false ? throw MalformedResponseSpecException::invalidBase64() : $decoded;
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
