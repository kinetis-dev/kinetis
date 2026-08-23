<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Session\Session;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private CacheSessionStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->store = new CacheSessionStore(new InMemorySessionCache());
    }

    public function test_values_round_trip_through_commit_and_a_second_session(): void
    {
        $first = new Session($this->store, null);
        $first->set('theme', 'dark');
        self::assertTrue($first->commit(60), 'A brand-new session needs its cookie written.');

        $second = new Session($this->store, $first->id());
        self::assertSame('dark', $second->get('theme'));
        self::assertTrue($second->has('theme'));
    }

    public function test_an_untouched_session_never_hits_the_store_and_needs_no_cookie(): void
    {
        $countingStore = new class implements \Kinetis\Session\SessionStoreInterface {
            public int $reads = 0;

            public int $writes = 0;

            public function read(string $id): ?array
            {
                $this->reads++;

                return null;
            }

            public function write(string $id, array $data, int $lifetimeSeconds): void
            {
                $this->writes++;
            }

            public function destroy(string $id): void {}
        };

        $session = new Session($countingStore, null);

        self::assertFalse($session->commit(60));
        self::assertSame(0, $countingStore->reads);
        self::assertSame(0, $countingStore->writes);
        self::assertFalse($session->wasLoaded());
    }

    public function test_a_read_only_use_of_an_existing_session_needs_no_new_cookie(): void
    {
        $first = new Session($this->store, null);
        $first->set('user', 42);
        $first->commit(60);

        $second = new Session($this->store, $first->id());
        self::assertSame(42, $second->get('user'));
        self::assertFalse($second->commit(60), 'Reading changes nothing; the browser already holds the cookie.');
    }

    public function test_remove_and_all_behave(): void
    {
        $session = new Session($this->store, null);
        $session->set('a', 1);
        $session->set('b', 2);
        $session->remove('a');

        self::assertFalse($session->has('a'));
        self::assertSame(['b' => 2], $session->all());
    }

    public function test_flash_data_survives_exactly_one_following_request(): void
    {
        $first = new Session($this->store, null);
        $first->flash('status', 'saved');
        $first->commit(60);
        $id = $first->id();

        $second = new Session($this->store, $id);
        self::assertSame('saved', $second->flashed('status'));
        $second->commit(60);

        $third = new Session($this->store, $id);
        self::assertNull($third->flashed('status'));
    }

    public function test_the_csrf_token_is_stable_within_and_across_requests(): void
    {
        $first = new Session($this->store, null);
        $token = $first->csrfToken();
        self::assertSame($token, $first->csrfToken());
        $first->commit(60);

        $second = new Session($this->store, $first->id());
        self::assertSame($token, $second->csrfToken());
    }

    public function test_the_csrf_token_never_appears_in_all(): void
    {
        $session = new Session($this->store, null);
        $session->csrfToken();
        $session->set('x', 1);

        self::assertSame(['x' => 1], $session->all());
    }

    public function test_regenerate_changes_the_id_keeps_data_and_destroys_the_old_payload(): void
    {
        $first = new Session($this->store, null);
        $first->set('user', 42);
        $first->commit(60);
        $oldId = $first->id();

        $second = new Session($this->store, $oldId);
        $second->regenerate();
        $newId = $second->id();
        self::assertNotSame($oldId, $newId);
        self::assertSame(42, $second->get('user'));
        $second->commit(60);

        self::assertNull($this->store->read($oldId), 'The fixated pre-regeneration id must dead-end.');
        $reloaded = new Session($this->store, $newId);
        self::assertSame(42, $reloaded->get('user'));
    }

    public function test_destroy_removes_the_payload_and_flags_for_cookie_expiry(): void
    {
        $first = new Session($this->store, null);
        $first->set('user', 42);
        $first->commit(60);

        $second = new Session($this->store, $first->id());
        $second->destroy();

        self::assertTrue($second->isDestroyed());
        self::assertNull($this->store->read($first->id()));
    }

    public function test_a_new_session_gets_a_wellformed_id(): void
    {
        $session = new Session($this->store, null);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->id());
    }
}
