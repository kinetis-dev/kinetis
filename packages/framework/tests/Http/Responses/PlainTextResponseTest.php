<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\PlainTextResponse;
use PHPUnit\Framework\TestCase;

final class PlainTextResponseTest extends TestCase
{
    public function test_sets_the_plain_text_content_type_and_body(): void
    {
        $response = PlainTextResponse::create('Hello, World!');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('Hello, World!', (string) $response->getBody());
    }

    public function test_accepts_a_custom_status(): void
    {
        $response = PlainTextResponse::create('Gone', 410);

        self::assertSame(410, $response->getStatusCode());
    }
}
