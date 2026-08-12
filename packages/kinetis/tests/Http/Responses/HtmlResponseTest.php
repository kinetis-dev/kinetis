<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\HtmlResponse;
use PHPUnit\Framework\TestCase;

final class HtmlResponseTest extends TestCase
{
    public function test_sets_the_html_content_type_and_body(): void
    {
        $response = HtmlResponse::create('<h1>Hello</h1>');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function test_accepts_a_custom_status(): void
    {
        $response = HtmlResponse::create('<h1>Gone</h1>', 410);

        self::assertSame(410, $response->getStatusCode());
    }
}
