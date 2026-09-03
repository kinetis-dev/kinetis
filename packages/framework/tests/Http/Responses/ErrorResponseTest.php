<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\ErrorResponse;
use PHPUnit\Framework\TestCase;

final class ErrorResponseTest extends TestCase
{
    public function test_builds_a_json_error_body_with_the_given_status(): void
    {
        $response = ErrorResponse::create(404, 'User 42 not found.');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'User 42 not found.'], json_decode((string) $response->getBody(), true));
    }

    public function test_extra_headers_are_carried_alongside_the_json_body(): void
    {
        $response = ErrorResponse::create(405, 'Method not allowed.', ['Allow' => 'GET, POST']);

        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_the_fixed_content_type_always_wins_over_a_caller_supplied_one(): void
    {
        $response = ErrorResponse::create(500, 'Boom.', ['Content-Type' => 'text/plain']);

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * $message is not necessarily framework-controlled text — a
     * satellite package's own HttpStatusExceptionInterface message can
     * be anything. A byte sequence that is not valid UTF-8 must still
     * produce parseable JSON, not an uncaught JsonException, and the
     * given $status must survive unchanged.
     */
    public function test_invalid_utf8_in_the_message_still_produces_parseable_json_at_the_given_status(): void
    {
        $response = ErrorResponse::create(400, "bad: \xC3\x28 end");

        self::assertSame(400, $response->getStatusCode());

        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // The exact, deterministic result of JSON_INVALID_UTF8_SUBSTITUTE:
        // the one byte sequence that isn't valid UTF-8 (\xC3\x28, a lone
        // lead byte followed by a byte that can't continue it) becomes
        // the Unicode replacement character, U+FFFD — not an
        // approximately-right string, an exact one, so an implementation
        // that dropped or otherwise mangled the malformed bytes would
        // fail this.
        self::assertSame("bad: \u{FFFD}( end", $decoded['error']);
    }
}
