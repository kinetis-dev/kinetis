<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Runtime;

use Kinetis\Testing\Runtime\MalformedResponseSpecException;
use Kinetis\Testing\Runtime\ResponseSpec;
use PHPUnit\Framework\TestCase;

/**
 * A response spec crosses a process boundary — the conformance suite
 * sends one as a header and a fixture running under a real SAPI answers
 * with it — and every response-side assertion the suite makes is only
 * worth anything if the handler answered with the spec the test wrote.
 * So the decode is exact, and a field that arrived missing, mistyped or
 * unreadable is a failure rather than a default.
 */
final class ResponseSpecTest extends TestCase
{
    public function test_a_spec_survives_the_round_trip_unchanged(): void
    {
        $spec = new ResponseSpec(
            201,
            [['Content-Type', 'text/event-stream']],
            ['a=1; Path=/'],
            "\xFF\x00binary",
            ['first', 'second'],
            300,
        );

        // Every field named at the call site above, because the
        // constructor has no defaults left to fall back on — a spec is
        // compared field by field across a process boundary, and a
        // default is a value the test never wrote.

        $decoded = ResponseSpec::fromArray($spec->toArray());

        self::assertEquals($spec, $decoded);
    }

    public function test_a_non_streaming_spec_keeps_its_null_stream_chunks(): void
    {
        $decoded = ResponseSpec::fromArray(ResponseSpec::json(200, '{}')->toArray());

        self::assertNull($decoded->streamChunks);
        self::assertSame(0, $decoded->streamDelayMs);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function requiredFields(): iterable
    {
        foreach (['status', 'headers', 'setCookies', 'body', 'streamChunks', 'streamDelayMs'] as $field) {
            yield $field => [$field];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFields')]
    public function test_every_field_is_required(string $field): void
    {
        $data = ResponseSpec::json(200, '{}')->toArray();
        unset($data[$field]);

        $this->expectException(MalformedResponseSpecException::class);
        $this->expectExceptionMessage("missing its \"{$field}\" field");

        ResponseSpec::fromArray($data);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'status as a string' => ['status', '200'];
        yield 'headers as a map' => ['headers', ['Content-Type' => 'application/json']];
        yield 'headers with a one-element pair' => ['headers', [['Content-Type']]];
        yield 'set-cookies as a map' => ['setCookies', ['a' => 'b']];
        yield 'body as a number' => ['body', 200];
        yield 'stream chunks as a string' => ['streamChunks', 'chunk'];
        yield 'stream delay as a string' => ['streamDelayMs', '300'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongTypes')]
    public function test_a_field_of_the_wrong_type_is_refused(string $field, mixed $value): void
    {
        $data = ResponseSpec::json(200, '{}')->toArray();
        $data[$field] = $value;

        $this->expectException(MalformedResponseSpecException::class);

        ResponseSpec::fromArray($data);
    }

    /**
     * Decoded as empty bytes, an unreadable body is indistinguishable
     * from a response with none at all — and the suite would then assert,
     * confidently, against the wrong thing.
     */
    public function test_a_body_that_is_not_valid_base64_is_refused_rather_than_decoded_as_empty(): void
    {
        $data = ResponseSpec::json(200, '{}')->toArray();
        $data['body'] = 'not base64 !!!';

        $this->expectException(MalformedResponseSpecException::class);
        $this->expectExceptionMessage('not valid base64');

        ResponseSpec::fromArray($data);
    }

    public function test_a_stream_chunk_that_is_not_valid_base64_is_refused(): void
    {
        $data = ResponseSpec::streaming(200, [], ['ok'], delayMs: 1)->toArray();
        $data['streamChunks'] = ['not base64 !!!'];

        $this->expectException(MalformedResponseSpecException::class);

        ResponseSpec::fromArray($data);
    }
}
