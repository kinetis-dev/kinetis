<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\CsrfWithoutSessionFixtureController;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use Kinetis\Session\Tests\Fixtures\InvocationRecorder;
use Kinetis\Session\Tests\Fixtures\RecordingSessionStore;
use Kinetis\Session\Tests\Fixtures\SessionFixtureController;
use Kinetis\Session\Tests\Fixtures\SideEffectProbeController;
use Kinetis\Testing\TestApplication;
use Kinetis\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The whole flow through a real Kernel: middleware discovery of the
 * Session on the request scope, the cookie round trip across requests,
 * and CSRF enforcement — not middleware units in isolation.
 */
final class SessionKernelTest extends TestCase
{
    private TestClient $client;

    /**
     * Only the flash-aging-on-success kernel regression uses this
     * directly, to seed a real token and pending flash data together
     * under one known id with no earlier HTTP request in between — an
     * intervening request touching the session (fetching a token via
     * `/token`, say) would itself run load() and age the flash before
     * the actual scenario under test ever got to run.
     */
    private SessionStoreInterface $store;

    #[\Override]
    protected function setUp(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([
            'SESSION_SECURE' => 'false',
        ]));
        $this->store = new CacheSessionStore(new InMemorySessionCache());
        $app->instance(SessionStoreInterface::class, $this->store);

        $router = new Router();
        $router->register(SessionFixtureController::class);
        $router->register(CsrfWithoutSessionFixtureController::class);

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

    /**
     * KINETIS-67: mutating an already-established session — no
     * regenerate()/destroy() involved, the ordinary and by far most
     * common case — must still refresh the Set-Cookie, with the same id
     * but a full, fresh Max-Age. SESSION_LIFETIME is counted from *this*
     * write; without a refreshed cookie, the browser's own Max-Age would
     * keep counting down from whenever the cookie was first issued while
     * the store's own expiry kept advancing on every mutation.
     */
    public function test_mutating_an_existing_session_refreshes_the_cookie_with_the_same_id_and_full_lifetime(): void
    {
        $first = $this->client->get('/remember/dark');
        $cookie = self::cookieFrom($first->getHeaderLine('Set-Cookie'));
        $id = \substr($cookie, \strlen('kinetis_session='));

        $second = $this->client->get('/remember/light', [], ['Cookie' => $cookie]);
        $second->assertOk();
        $setCookie = $second->getHeaderLine('Set-Cookie');

        self::assertStringContainsString(
            "kinetis_session={$id}",
            $setCookie,
            'a mutation is not a regeneration — the id itself must be unchanged.',
        );
        self::assertStringContainsString(
            'Max-Age=7200',
            $setCookie,
            'a mutation must refresh the cookie with the full configured lifetime, not merely repeat an old one.',
        );

        // The read-only counterpart, using the just-refreshed cookie:
        // still sends no cookie at all — preserved exactly as before.
        $refreshedCookie = self::cookieFrom($setCookie);
        $third = $this->client->get('/recall', [], ['Cookie' => $refreshedCookie]);
        $third->assertOk()->assertJsonPath('remembered', 'light');
        self::assertSame('', $third->getHeaderLine('Set-Cookie'), 'a read-only request still sends no cookie.');
    }

    /**
     * KINETIS-67's own explicit requirement: a store write that fails
     * must never let the response carry a refreshed cookie — the
     * exception propagates uncaught (the same "must remain visible to
     * the outer exception boundary" discipline KINETIS-65 already
     * established), reaching ExceptionHandlerMiddleware's own 500
     * before SessionMiddleware's commit()/cookie code is ever reached.
     */
    public function test_a_store_write_failure_never_lets_the_response_carry_a_refreshed_cookie(): void
    {
        $id = \str_repeat('a', 32);
        $store = new RecordingSessionStore(throwOnWrite: new RuntimeException('store unavailable'));
        $store->seed($id, ['remembered' => 'kept']);

        $client = $this->clientWithStore($store);

        $response = $client->get('/remember/changed', [], ['Cookie' => "kinetis_session={$id}"]);

        $response->assertStatus(500);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'), 'a failed store write must never let the response carry a refreshed cookie.');
    }

    public function test_a_route_that_never_touches_the_session_sets_no_cookie(): void
    {
        $response = $this->client->get('/untouched');

        $response->assertOk();
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * Calling id() on a brand-new, no-cookie session is an explicit
     * request for a stable identity, not a read-only access — unlike
     * get()/has() on the same fresh session (which stay lazy, no store
     * round trip, no cookie), the id handed back here must actually be
     * persisted and sent to the client, or it dies at request end and
     * the "requesting the id is itself session use" contract is a lie.
     */
    public function test_id_only_establishes_and_persists_a_new_session(): void
    {
        $first = $this->client->get('/id-only');
        $first->assertOk();

        $setCookie = $first->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('kinetis_session=', $setCookie, 'id() alone must still set a cookie.');

        $bodyId = $first->json()['id'];
        self::assertIsString($bodyId);
        self::assertStringContainsString("kinetis_session={$bodyId}", $setCookie, 'The body id and the cookie id must match.');

        $cookie = self::cookieFrom($setCookie);
        $second = $this->client->get('/id-only', [], ['Cookie' => $cookie]);
        $second->assertOk();

        self::assertSame($bodyId, $second->json()['id'], 'A second request with the cookie must resolve the same id.');
        self::assertSame('', $second->getHeaderLine('Set-Cookie'), 'A second, already-cookied request must not re-send the cookie.');
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

    /**
     * A wellformed but nonexistent id (unlike the malformed one above,
     * which SessionMiddleware itself filters out) is the actual
     * session-fixation primitive: an attacker plants a chosen id in a
     * victim's browser and waits for the framework to write real state
     * under it. The store never even holding this id must be enough to
     * reject it — attacking with a real, previously-issued id from a
     * different session is exactly test_regenerate_rotates_the_cookie_
     * and_the_old_id_dead_ends()'s own scenario, covered separately.
     */
    public function test_a_chosen_but_unknown_cookie_id_never_becomes_the_session(): void
    {
        $chosen = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $response = $this->client->get('/remember/secret', [], ['Cookie' => "kinetis_session={$chosen}"]);
        $response->assertOk();

        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/kinetis_session=[a-f0-9]{32}/', $setCookie);
        self::assertStringNotContainsString("kinetis_session={$chosen}", $setCookie);

        $replacement = self::cookieFrom($setCookie);

        $this->client->get('/recall', [], ['Cookie' => "kinetis_session={$chosen}"])
            ->assertJsonPath('remembered', null);
        $this->client->get('/recall', [], ['Cookie' => $replacement])
            ->assertJsonPath('remembered', 'secret');
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

    /**
     * KINETIS-70: a wrong header token, with no cookie sent at all, must
     * reject and persist nothing — before this fix, checking the wrong
     * token against a session that doesn't exist yet was itself what
     * allocated and persisted one, letting an attacker force one session
     * record per request without ever fetching a form. No Set-Cookie is
     * the same "no store write happened" proof used throughout this
     * file (SessionMiddleware only ever sends one when commit() reports
     * something changed).
     */
    public function test_csrf_rejects_a_wrong_header_token_with_no_cookie_and_writes_nothing(): void
    {
        $response = $this->client->post('/guarded', [], ['X-CSRF-Token' => \str_repeat('0', 40)]);

        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * KINETIS-70: the well-formed-but-nonexistent-cookie variant of the
     * same attack — a cookie the store has never heard of is exactly
     * what load()'s own rotation branch handles, and before this fix
     * that rotation was itself enough to persist a fresh, empty session
     * regardless of what the token check found.
     */
    public function test_csrf_rejects_a_wrong_header_token_with_an_unknown_cookie_and_writes_nothing(): void
    {
        $chosen = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $response = $this->client->post('/guarded', [], [
            'Cookie' => "kinetis_session={$chosen}",
            'X-CSRF-Token' => \str_repeat('0', 40),
        ]);

        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /** KINETIS-70: the same proof, through the form-field fallback rather than the header. */
    public function test_csrf_rejects_a_wrong_form_token_with_no_cookie_and_writes_nothing(): void
    {
        $response = $this->client->postForm('/guarded', ['_token' => \str_repeat('0', 40)]);

        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_csrf_rejects_a_wrong_form_token_with_an_unknown_cookie_and_writes_nothing(): void
    {
        $chosen = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $response = $this->client->postForm(
            '/guarded',
            ['_token' => \str_repeat('0', 40)],
            ['Cookie' => "kinetis_session={$chosen}"],
        );

        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * "Valid tokens still pass" through the form-field path specifically
     * — the header path's own equivalent is
     * test_csrf_accepts_the_sessions_own_token_via_header() above.
     */
    public function test_csrf_accepts_a_valid_token_via_form_field(): void
    {
        $seeded = $this->client->get('/token');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));
        $token = $seeded->json()['token'];
        self::assertIsString($token);

        $response = $this->client->postForm('/guarded', ['_token' => $token], ['Cookie' => $cookie]);

        $response->assertOk()->assertJsonPath('changed', true);
    }

    /**
     * KINETIS-70: the other direction the fix has to hold — a mismatch
     * against a genuinely existing session must reject the request
     * without touching that session at all. Proven by reading the real
     * data back afterward through the same cookie, not just by checking
     * the 403 itself.
     */
    public function test_csrf_rejects_a_wrong_token_but_does_not_mutate_an_existing_real_session(): void
    {
        $remembered = $this->client->get('/remember/secret');
        $cookie = self::cookieFrom($remembered->getHeaderLine('Set-Cookie'));

        $seeded = $this->client->get('/token', [], ['Cookie' => $cookie]);
        // Generating the token for the first time on an already-existing
        // session is itself a write (KINETIS-67), so the cookie may
        // refresh here — the id itself does not change, but the latest
        // cookie value is what a real browser would actually hold.
        $latestSetCookie = $seeded->getHeaderLine('Set-Cookie');
        $cookie = $latestSetCookie !== '' ? self::cookieFrom($latestSetCookie) : $cookie;

        $this->client->post('/guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => \str_repeat('0', 40)])
            ->assertStatus(403);

        $this->client->get('/recall', [], ['Cookie' => $cookie])
            ->assertOk()
            ->assertJsonPath('remembered', 'secret');
    }

    /**
     * KINETIS-70 FEEDBACK: the sharper version of the test directly
     * above. verifyCsrfToken() reaches into the same session data
     * load() would, and load() marks a session dirty whenever pending
     * flash data is found — entirely independent of CSRF, a real,
     * pre-existing design for the ordinary case (reading is itself
     * what ages a flash value). Checking a wrong token must not ride
     * along on that: the flash generation /flash-set/{value} wrote
     * must still be readable, completely unaged, on the very next
     * legitimate request — proving the rejected POST scheduled no
     * write at all, not merely that it avoided creating a new session.
     */
    public function test_csrf_rejects_a_wrong_header_token_and_does_not_age_pending_flash_data(): void
    {
        $seeded = $this->client->get('/flash-set/saved');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $response = $this->client->post('/guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => \str_repeat('0', 40)]);
        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));

        $this->client->get('/flash-read', [], ['Cookie' => $cookie])
            ->assertOk()
            ->assertJsonPath('status', 'saved');
    }

    /** The same proof, through the form-field fallback rather than the header. */
    public function test_csrf_rejects_a_wrong_form_token_and_does_not_age_pending_flash_data(): void
    {
        $seeded = $this->client->get('/flash-set/saved');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $response = $this->client->postForm('/guarded', ['_token' => \str_repeat('0', 40)], ['Cookie' => $cookie]);
        $response->assertStatus(403);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));

        $this->client->get('/flash-read', [], ['Cookie' => $cookie])
            ->assertOk()
            ->assertJsonPath('status', 'saved');
    }

    /**
     * KINETIS-70 FEEDBACK 2: the counterpart to the two tests directly
     * above — a *successful* verification must itself age pending flash
     * data, even through a guarded handler
     * (SessionFixtureController::guarded()) that never touches Session
     * at all beyond what CsrfMiddleware itself does. Seeded directly in
     * the store under one known id, with no earlier HTTP request in
     * between that could age the flash on its own first.
     */
    public function test_csrf_accepts_a_valid_token_and_ages_pending_flash_data_even_though_the_handler_never_touches_the_session(): void
    {
        $knownId = \str_repeat('5', 32);
        $this->store->write($knownId, ['_csrf' => 'the-real-token', '_flash.old' => ['status' => 'saved']], 7200);
        $cookie = "kinetis_session={$knownId}";

        $this->client->post('/guarded', [], ['Cookie' => $cookie, 'X-CSRF-Token' => 'the-real-token'])
            ->assertOk()
            ->assertJsonPath('changed', true);

        $this->client->get('/flash-read', [], ['Cookie' => $cookie])
            ->assertOk()
            ->assertJsonPath('status', null);
    }

    /**
     * CsrfMiddleware declared without SessionMiddleware ahead of it on
     * the same route — a declaration-order mistake, not an attacker
     * probing without a token — must fail with the clean, explanatory
     * 500 this middleware documents, not an uncaught NotFoundException
     * surfacing from deep inside Session's own constructor trying (and
     * failing) to autowire SessionStoreInterface.
     */
    public function test_csrf_without_session_middleware_ahead_of_it_fails_clearly(): void
    {
        $this->client->post('/csrf-without-session')
            ->assertStatus(500)
            ->assertJsonPath('error', 'CsrfMiddleware needs SessionMiddleware declared before it on this route.');
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

    /**
     * KINETIS-64: an exception thrown after regenerate() must never reach
     * SessionMiddleware's own commit()/cookie code — it unwinds straight
     * to ExceptionHandlerMiddleware instead. The original, pre-request
     * session must therefore still be exactly as it was: no Set-Cookie
     * sent (the browser's existing cookie is still correct), and its data
     * still readable under that same original cookie.
     */
    public function test_an_exception_after_regenerate_leaves_the_original_session_and_cookie_untouched(): void
    {
        $seeded = $this->client->get('/remember/kept');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $failed = $this->client->get('/rotate-then-throw', [], ['Cookie' => $cookie]);
        $failed->assertStatus(500);
        self::assertSame('', $failed->getHeaderLine('Set-Cookie'), 'a request that throws before commit() must send no cookie at all.');

        $this->client->get('/recall', [], ['Cookie' => $cookie])->assertJsonPath('remembered', 'kept');
    }

    /**
     * The same proof for destroy(): the store deletion and the cookie
     * expiry both happen inside commit(), which a thrown exception never
     * reaches — so the original session must still be fully intact.
     */
    public function test_an_exception_after_destroy_leaves_the_original_session_and_cookie_untouched(): void
    {
        $seeded = $this->client->get('/remember/kept');
        $cookie = self::cookieFrom($seeded->getHeaderLine('Set-Cookie'));

        $failed = $this->client->get('/logout-then-throw', [], ['Cookie' => $cookie]);
        $failed->assertStatus(500);
        self::assertSame('', $failed->getHeaderLine('Set-Cookie'), 'a request that throws before commit() must send no cookie at all.');

        $this->client->get('/recall', [], ['Cookie' => $cookie])->assertJsonPath('remembered', 'kept');
    }

    /**
     * @param array<string, string> $config
     */
    private function clientWith(array $config): TestClient
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        // AppScope::boot() detects the environment from the real process
        // environment, which an array-built Config cannot reach — so an
        // APP_ENV given here is registered directly, the same as
        // TestApplication does for its own overrides.
        $app->instance(AppEnvironment::class, AppEnvironment::detect($config['APP_ENV'] ?? null));
        $app->instance(SessionStoreInterface::class, new CacheSessionStore(new InMemorySessionCache()));

        $router = new Router();
        $router->register(SessionFixtureController::class);

        return TestApplication::withRouter($router, $app)->client();
    }

    /**
     * Like clientWith(), but with a caller-supplied store — for a test
     * that needs a store configured to fail on a specific operation
     * (RecordingSessionStore's own $throwOnWrite/$throwOnDestroy),
     * rather than the always-succeeding real one every other test here
     * uses. SESSION_SECURE=false, matching this file's own shared
     * $this->client default, since none of these tests exercise TLS.
     */
    private function clientWithStore(SessionStoreInterface $store): TestClient
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['SESSION_SECURE' => 'false']));
        $app->instance(SessionStoreInterface::class, $store);

        $router = new Router();
        $router->register(SessionFixtureController::class);

        return TestApplication::withRouter($router, $app)->client();
    }

    /**
     * A separate client/router from clientWith(): the recorder-observing
     * probe route lives on its own controller so every other test above
     * keeps using SessionFixtureController's own plain constructor.
     *
     * @param array<string, string> $config
     * @return array{0: TestClient, 1: InvocationRecorder}
     */
    private function clientWithRecorder(array $config): array
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $app->instance(AppEnvironment::class, AppEnvironment::detect($config['APP_ENV'] ?? null));
        $app->instance(SessionStoreInterface::class, new CacheSessionStore(new InMemorySessionCache()));
        $recorder = new InvocationRecorder();
        $app->instance(InvocationRecorder::class, $recorder);

        $router = new Router();
        $router->register(SideEffectProbeController::class);

        return [TestApplication::withRouter($router, $app)->client(), $recorder];
    }

    /**
     * A browser rejects a `__Host-` cookie that is not Secure, or is
     * scoped by Path or Domain — so the assertion that matters is on the
     * header as sent, not on the name alone.
     */
    public function test_a_host_prefixed_cookie_is_sent_with_the_attributes_the_prefix_requires(): void
    {
        $client = $this->clientWith(['SESSION_COOKIE' => '__Host-kinetis_session']);

        $response = $client->get('/remember/dark');
        $setCookie = $response->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('__Host-kinetis_session=', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);
        self::assertStringNotContainsString('Domain=', $setCookie);

        // And it still round-trips, which the prefix must not disturb.
        $client->get('/recall', [], ['Cookie' => self::cookieFrom($setCookie)])
            ->assertJsonPath('remembered', 'dark');
    }

    public function test_a_secure_prefixed_cookie_is_sent_as_secure(): void
    {
        $setCookie = $this->clientWith(['SESSION_COOKIE' => '__Secure-kinetis_session'])
            ->get('/remember/dark')
            ->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('__Secure-kinetis_session=', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
    }

    /**
     * The combination a browser would silently drop on every response,
     * refused where it can still be read as a configuration mistake.
     */
    public function test_a_prefixed_cookie_without_secure_is_refused(): void
    {
        foreach (['__Host-sid', '__Secure-sid'] as $name) {
            // APP_ENV=development so the 500 carries the real message,
            // which is the half worth asserting: whoever hits this needs
            // to be told which setting to change.
            $client = $this->clientWith([
                'SESSION_COOKIE' => $name,
                'SESSION_SECURE' => 'false',
                'APP_ENV' => 'development',
            ]);

            $response = $client->get('/remember/dark');

            $response->assertStatus(500);
            self::assertStringContainsString('SESSION_SECURE', (string) $response->getBody(), $name);
        }
    }

    /**
     * Lower-cased, the prefix is an ordinary name that no browser
     * enforces, so it must not be treated as carrying a guarantee.
     */
    public function test_a_lowercased_prefix_is_an_ordinary_cookie_name(): void
    {
        $setCookie = $this->clientWith(['SESSION_COOKIE' => '__host-sid', 'SESSION_SECURE' => 'false'])
            ->get('/remember/dark')
            ->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('__host-sid=', $setCookie);
        self::assertStringNotContainsString('Secure', $setCookie);
    }

    public function test_a_cookie_name_that_cannot_be_sent_verbatim_is_refused(): void
    {
        foreach (['sid session', "sid\r\nX-Injected: 1", 'sid;Path=/evil', ''] as $name) {
            $response = $this->clientWith(['SESSION_COOKIE' => $name, 'APP_ENV' => 'development'])
                ->get('/remember/dark');

            $response->assertStatus(500);
            self::assertStringContainsString('valid cookie name', (string) $response->getBody(), var_export($name, true));
        }
    }

    public function test_an_invalid_session_lifetime_is_refused(): void
    {
        foreach (['0', '-1', 'not-a-number', '99999999999999999999999999999999'] as $lifetime) {
            $response = $this->clientWith(['SESSION_LIFETIME' => $lifetime, 'APP_ENV' => 'development'])
                ->get('/remember/dark');

            $response->assertStatus(500);
            self::assertStringContainsString('SESSION_LIFETIME', (string) $response->getBody(), $lifetime);
        }
    }

    /**
     * SessionMiddleware now parses and validates SESSION_LIFETIME in its
     * own constructor — before the request reaches the inner handler at
     * all — so an invalid value must mean the controller genuinely never
     * runs, not just that the response eventually comes back 500. A
     * shared InvocationRecorder is what makes that observable: a
     * middleware construction failure never reaches the controller, so
     * the count staying zero is what proves it, independent of the
     * response status.
     */
    public function test_the_inner_handler_never_runs_with_an_invalid_session_lifetime(): void
    {
        foreach (['0', '-1', 'not-a-number', '99999999999999999999999999999999'] as $lifetime) {
            [$client, $recorder] = $this->clientWithRecorder(['SESSION_LIFETIME' => $lifetime, 'APP_ENV' => 'development']);

            $response = $client->get('/side-effect-probe');

            $response->assertStatus(500);
            self::assertSame(0, $recorder->calls, "handler ran despite SESSION_LIFETIME=\"{$lifetime}\"");
        }
    }

    public function test_the_inner_handler_runs_exactly_once_with_a_valid_session_lifetime(): void
    {
        [$client, $recorder] = $this->clientWithRecorder(['SESSION_LIFETIME' => '3600']);

        $client->get('/side-effect-probe')->assertOk();

        self::assertSame(1, $recorder->calls);
    }

    /**
     * KINETIS-68 FEEDBACK: a SESSION_LIFETIME too large for every
     * backend this package ships to store (unlike the data-provider
     * cases above, this value is a syntactically ordinary PHP int —
     * Config::int() accepts it without complaint, so this is genuinely
     * exercising SessionExpiry's own MAX_EXPIRES_AT check, not Config's
     * separate int-range check) must fail at middleware construction,
     * before the handler ever runs — a request must never perform real
     * application side effects only to have commit() throw afterward
     * for a value that was already known bad.
     *
     * KINETIS-69: this value is chosen relative to SessionExpiry's own
     * MAX_EXPIRES_AT rather than hardcoded independently of it — a fixed
     * literal here silently stopped testing anything real once
     * MAX_EXPIRES_AT's own value changed (this exact test passed for the
     * wrong reason, with the handler genuinely running, until this fix
     * was caught by re-running the full suite after that change). Even
     * at today's real time(), 260 billion seconds is comfortably past
     * MAX_EXPIRES_AT (roughly 8,000 years from now) regardless of when
     * this test actually runs, so no time()-tolerant window is needed
     * here the way SessionExpiryTest's own boundary tests need one.
     */
    public function test_the_inner_handler_never_runs_with_a_session_lifetime_beyond_the_portable_maximum(): void
    {
        [$client, $recorder] = $this->clientWithRecorder(['SESSION_LIFETIME' => '260000000000', 'APP_ENV' => 'development']);

        $response = $client->get('/side-effect-probe');

        $response->assertStatus(500);
        self::assertStringContainsString('SESSION_LIFETIME', (string) $response->getBody());
        self::assertSame(0, $recorder->calls, 'the handler must never run for a SESSION_LIFETIME beyond the portable maximum.');
    }

    public function test_an_unrecognised_same_site_value_is_refused(): void
    {
        foreach (['Invalid', 'Lax;Domain=evil.com', ''] as $value) {
            $response = $this->clientWith(['SESSION_SAMESITE' => $value, 'APP_ENV' => 'development'])
                ->get('/remember/dark');

            $response->assertStatus(500);
            self::assertStringContainsString('SESSION_SAMESITE', (string) $response->getBody(), var_export($value, true));
        }
    }

    /**
     * A browser drops a SameSite=None cookie that is not Secure, so the
     * session would be lost on every response. Refused where it can still
     * be read as a configuration mistake.
     */
    public function test_same_site_none_without_secure_is_refused(): void
    {
        $response = $this->clientWith([
            'SESSION_SAMESITE' => 'None',
            'SESSION_SECURE' => 'false',
            'APP_ENV' => 'development',
        ])->get('/remember/dark');

        $response->assertStatus(500);
        self::assertStringContainsString('SESSION_SECURE', (string) $response->getBody());
    }

    public function test_same_site_none_is_sent_when_the_cookie_is_secure(): void
    {
        $setCookie = $this->clientWith(['SESSION_SAMESITE' => 'None'])
            ->get('/remember/dark')
            ->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('SameSite=None', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
    }

    /**
     * A browser matches the attribute case-insensitively, but the header
     * is written once and read by everything downstream, so it is
     * normalised rather than echoed as typed.
     */
    public function test_a_same_site_value_is_normalised_to_its_canonical_casing(): void
    {
        foreach (['strict' => 'Strict', 'LAX' => 'Lax', 'nOnE' => 'None'] as $configured => $expected) {
            $setCookie = $this->clientWith(['SESSION_SAMESITE' => $configured])
                ->get('/remember/dark')
                ->getHeaderLine('Set-Cookie');

            self::assertStringContainsString("SameSite={$expected}", $setCookie, $configured);
        }
    }
}
