<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Kinetis\Testing\ApplicationTestCase;

final class WelcomeControllerTest extends ApplicationTestCase
{
    protected function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_the_welcome_page_renders_successfully(): void
    {
        // The route is discovered, not registered here — the same way a
        // real request finds it.
        $this->client->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertBodyContains('Kinetis')
            ->assertBodyContains('Zero configuration')
            ->assertBodyContains('docs.kinetis.dev');
    }

    public function test_an_unknown_path_is_a_404(): void
    {
        $this->client->get('/nope')->assertNotFound();
    }
}
