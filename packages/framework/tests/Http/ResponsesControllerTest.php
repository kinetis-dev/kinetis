<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\ResponsesController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ResponsesControllerTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(ResponsesController::class);

        $this->kernel = new Kernel($app, $router);
    }

    public function test_a_route_can_return_html(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', '/page'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function test_a_route_can_return_a_file_download(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', '/avatar'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="avatar.png"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('not-really-a-png', (string) $response->getBody());
    }

    public function test_a_route_can_redirect(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', '/old-page'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/new-page', $response->getHeaderLine('Location'));
    }
}
