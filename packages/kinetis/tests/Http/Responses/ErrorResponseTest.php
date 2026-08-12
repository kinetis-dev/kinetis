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
}
