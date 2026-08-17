<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Routing\Router;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use Kinetis\Session\Tests\Fixtures\SessionFixtureController;
use Kinetis\Testing\TestApplication;
use Kinetis\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The whole flow through a real Kernel: middleware discovery of the
 * Session on the request scope, the cookie round trip across requests,
 * and CSRF enforcement — not middleware units in isolation.
 */
final class SessionKernelTest extends TestCase
{
    private TestClient $client;

    #[\Override]
    protected function setUp(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([
            'SESSION_SECURE' => 'false',
        ]));
        $app->instance(SessionStoreInterface::class, new CacheSessionStore(new InMemorySessionCache()));

        $router = new Router();
        $router->register(SessionFixtureController::class);

        $this->client = TestApplication::withRouter($router, $app)->client();
    }

    /** The Cookie request header a browser would send back. */
    private static function cookieFrom(string $setCookie): string
    {
        return \explode(';', $setCookie)[0];
    }

    public function test_a_session_value_survives_into_the_next_request(): void
    {
        $first = $this->client->get('/remember/dark');
        $first->assertOk();
        $setCookie = $first->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('kinetis_session=', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Lax', $setCookie);
        self::assertStringNotContainsString('Secure', $setCookie);

        $second = $this->client->get('/recall', [], ['Cookie' => self::cookieFrom($setCookie)]);
        $second->assertOk()->assertJsonPath('remembered', 'dark');
        self::assertSame('', $second->getHeaderLine('Set-Cookie'), 'A read-only request re-sets no cookie.');
    }

    public function test_a_route_that_never_touches_the_session_sets_no_cookie(): void
    {
        $response = $this->client->get('/untouched');

        $response->assertOk();
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_a_tampered_cookie_id_gets_a_fresh_session(): void
    {
        $response = $this->client->get('/remember/x', [], ['Cookie' => 'kinetis_session=../../evil']);

        $response->assertOk();
        self::assertMatchesRegularExpression(
            '/kinetis_session=[a-f0-9]{32}/',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    public function test_csrf_blocks_a_post_without_a_token(): void
    {
        $seeded = $this->client->get('/token');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $this->client->post('/guarded', [], ['Cookie' => $cookie])
            ->assertStatus(403);
    }

    public function test_csrf_accepts_the_sessions_own_token_via_header(): void
    {
        $seeded = $this->client->get('/token');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));
        $token = $seeded->json()['token'];
        self::assertIsString($token);

        $this->client->post('/guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => $token])
            ->assertOk()
            ->assertJsonPath('changed', true);
    }

    public function test_csrf_rejects_a_wrong_token(): void
    {
        $seeded = $this->client->get('/token');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $this->client->post('/guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => \str_repeat('0', 40)])
            ->assertStatus(403);
    }

    public function test_regenerate_rotates_the_cookie_and_the_old_id_dead_ends(): void
    {
        $first = $this->client->get('/remember/kept');
        $oldCookie = self::cookieFrom($first->getHeaderLine('Set-Cookie'));

        $rotated = $this->client->get('/rotate', [], ['Cookie' => $oldCookie]);
        $newCookie = self::cookieFrom($rotated->getHeaderLine('Set-Cookie'));
        self::assertNotSame($oldCookie, $newCookie);

        // Data followed the rotation; the fixated pre-rotation id is dead.
        $this->client->get('/recall', [], ['Cookie' => $newCookie])->assertJsonPath('remembered', 'kept');
        $this->client->get('/recall', [], ['Cookie' => $oldCookie])->assertJsonPath('remembered', null);
    }

    public function test_destroy_expires_the_cookie(): void
    {
        $first = $this->client->get('/remember/x');
        $cookie = self::cookieFrom($first->getHeaderLine('Set-Cookie'));

        $out = $this->client->get('/logout', [], ['Cookie' => $cookie]);
        $setCookie = $out->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('kinetis_session=;', $setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie);

        $this->client->get('/recall', [], ['Cookie' => $cookie])->assertJsonPath('remembered', null);
    }
}
