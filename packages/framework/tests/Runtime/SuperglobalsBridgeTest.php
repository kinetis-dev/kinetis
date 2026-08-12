<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Http\StreamedResponse;
use Kinetis\Runtime\SuperglobalsBridge;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SuperglobalsBridgeTest extends TestCase
{
    public function test_emit_echoes_a_plain_responses_body(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], 'hello');

        ob_start();
        SuperglobalsBridge::emit($response);
        $output = ob_get_clean();

        self::assertSame('hello', $output);
    }

    public function test_emit_calls_a_streamed_responses_emitter_instead_of_reading_its_body(): void
    {
        $called = false;
        $inner = new Response(200, ['Content-Type' => 'text/event-stream']);
        $response = new StreamedResponse($inner, static function () use (&$called): void {
            $called = true;
            echo 'streamed';
        });

        ob_start();
        SuperglobalsBridge::emit($response);
        $output = ob_get_clean();

        self::assertTrue($called);
        self::assertSame('streamed', $output);
    }
}
