<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Adapters;

use Kinetis\Runtime\Adapters\FpmAdapter;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class FpmAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    public function test_is_not_persistent(): void
    {
        self::assertFalse((new FpmAdapter())->isPersistent());
    }

    public function test_run_builds_a_request_from_superglobals_and_emits_the_response(): void
    {
        $seenRequest = null;

        ob_start();
        (new FpmAdapter())->run(function (ServerRequestInterface $request) use (&$seenRequest) {
            $seenRequest = $request;

            return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
        });
        $output = ob_get_clean();

        self::assertSame('/users', $seenRequest?->getUri()->getPath());
        self::assertSame('GET', $seenRequest?->getMethod());
        self::assertSame(200, http_response_code());
        self::assertSame('{"ok":true}', $output);
    }
}
