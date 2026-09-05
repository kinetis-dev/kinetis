<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Adapters;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Adapters\FpmAdapter;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
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
        self::assertFalse((new FpmAdapter(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES), TrustedProxies::fromList([])))->isPersistent());
    }

    /**
     * `enable_post_data_reading` is `PHP_INI_PERDIR`, so a process that
     * started without it cannot turn it off — which is exactly why this
     * adapter refuses rather than degrades, and why the working path is
     * proven where the setting can actually be set: the conformance
     * suites, which spawn `php -S -d enable_post_data_reading=0` and run
     * the real FPM and FrankenPHP containers under the same ini. Here,
     * under an unconfigured CLI, the only correct outcome is the refusal
     * — and the handler never running is the half that matters.
     */
    public function test_a_sapi_that_still_reads_the_body_itself_is_refused_before_the_handler(): void
    {
        self::assertTrue((bool) ini_get('enable_post_data_reading'), 'this test is only meaningful while the setting is on');

        $handlerRan = false;

        $this->expectException(RuntimeUnavailableException::class);
        $this->expectExceptionMessage('enable_post_data_reading must be 0');

        try {
            (new FpmAdapter(new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES), TrustedProxies::fromList([])))
                ->run(function (ServerRequestInterface $request) use (&$handlerRan) {
                    $handlerRan = true;

                    return new Response(200);
                });
        } finally {
            self::assertFalse($handlerRan, 'a misconfigured SAPI must be caught before the handler ever runs');
        }
    }
}
