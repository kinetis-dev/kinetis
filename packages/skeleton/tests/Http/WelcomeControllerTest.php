<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\WelcomeController;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class WelcomeControllerTest extends TestCase
{
    public function test_the_welcome_page_renders_successfully(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(WelcomeController::class);

        $client = new TestClient(new Kernel($app, $router));

        $response = $client->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Kinetis', $body);
        self::assertStringContainsString('Zero configuration', $body);
        self::assertStringContainsString('docs.kinetis.dev', $body);
    }
}
