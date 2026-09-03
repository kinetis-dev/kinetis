<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Session\Session;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use Kinetis\Session\Tests\Fixtures\RecordingSessionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    /**
     * KINETIS-67: the ordinary, by-far-most-common case — mutating an
     * existing session with no regenerate()/destroy() involved — must
     * still request a cookie refresh, even though the id itself never
     * changes. $lifetimeSeconds is counted from *this* write; without a
     * refreshed Set-Cookie, the browser's own Max-Age would keep counting
     * down from whenever the cookie was first issued while the store's
     * expiry kept advancing on every mutation, letting the browser
     * discard a cookie the store still considers perfectly live.
     */
    public function test_mutating_an_existing_session_still_requires_a_cookie_refresh(): void
    {
        $first = new Session($this->store, null);
        $first->set('user', 42);
        $first->commit(60);
        $id = $first->id();

        $second = new Session($this->store, $id);
        $second->set('user', 43);

        self::assertTrue($second->commit(60), 'a real mutation must refresh the cookie even though the id is unchanged.');
        self::assertSame($id, $second->id(), 'this is an ordinary mutation, not a regeneration — the id itself must not change.');
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

    /**
     * KINETIS-67: reading a flashed value ages it — the OLD generation
     * has to be removed from the store at the next commit — which is
     * itself a real write, not a read, and must refresh the cookie the
     * same as an explicit set() would, even though nothing was ever
     * explicitly written this request.
     */
    public function test_reading_a_flashed_value_still_requires_a_cookie_refresh(): void
    {
        $first = new Session($this->store, null);
        $first->flash('status', 'saved');
        $first->commit(60);
        $id = $first->id();

        $second = new Session($this->store, $id);
        $second->flashed('status');

        self::assertTrue($second->commit(60), 'aging flash data is a real write and must refresh the cookie too.');
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

    /**
     * KINETIS-67: generating the CSRF token for the first time on an
     * already-existing session (not a brand-new one) is itself a real
     * write — the token has to be persisted to be checked against
     * later — so it must refresh the cookie too, even though the id
     * itself is unchanged and nothing was ever explicitly set().
     */
    public function test_generating_the_csrf_token_on_an_existing_session_still_requires_a_cookie_refresh(): void
    {
        $first = new Session($this->store, null);
        $first->set('user', 42);
        $first->commit(60);
        $id = $first->id();

        $second = new Session($this->store, $id);
        $second->csrfToken();

        self::assertTrue($second->commit(60), 'generating the CSRF token for the first time is a real write and must refresh the cookie.');
    }

    public function test_the_csrf_token_never_appears_in_all(): void
    {
        $session = new Session($this->store, null);
        $session->csrfToken();
        $session->set('x', 1);

        self::assertSame(['x' => 1], $session->all());
    }

    /**
     * KINETIS-70: the whole reason verifyCsrfToken() exists — checking a
     * submitted token, right or wrong, against a session with no token
     * yet must never itself create one, unlike csrfToken() (which
     * exists specifically to do that, for the legitimate form-rendering
     * case). A brand-new session has nothing to match, so any submitted
     * value is rejected.
     */
    public function test_verify_csrf_token_never_generates_a_token(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, null);

        self::assertFalse($session->verifyCsrfToken('anything'));
        self::assertFalse($session->commit(60), 'checking a token, even a wrong one, must not itself persist anything.');
        self::assertSame([], $store->writes);
    }

    public function test_verify_csrf_token_accepts_the_real_token(): void
    {
        $session = new Session($this->store, null);
        $token = $session->csrfToken();

        self::assertTrue($session->verifyCsrfToken($token));
    }

    public function test_verify_csrf_token_rejects_a_wrong_token_against_a_real_one(): void
    {
        $session = new Session($this->store, null);
        $session->csrfToken();

        self::assertFalse($session->verifyCsrfToken('definitely-not-the-real-token'));
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

        self::assertTrue($second->isDestroyed(), 'destroy() itself is a purely in-memory flag — set immediately.');
        self::assertSame(['user' => 42], $this->store->read($first->id()), 'the actual deletion is deferred to commit().');

        self::assertTrue($second->commit(60));
        self::assertNull($this->store->read($first->id()), 'commit() is what actually removes the stored payload.');
    }

    /**
     * regenerate() only ever changes in-memory state — a store that
     * throws on every destroy() call proves this by never actually
     * throwing here at all: nothing in regenerate() ever calls destroy().
     */
    public function test_regenerate_never_touches_the_store_itself(): void
    {
        $knownId = \str_repeat('d', 32);
        $store = new RecordingSessionStore(new RuntimeException('store unavailable'));
        $store->seed($knownId, ['user' => 42]);

        $session = new Session($store, $knownId);
        $session->regenerate();

        self::assertNotSame($knownId, $session->id());
        self::assertSame([], $store->destroys, 'regenerate() itself must never call the store.');
    }

    /**
     * The order KINETIS-64's own fix depends on: the replacement id's
     * data must be durably written before the old, now-superseded id is
     * removed — never the other way around, which would make a
     * mid-commit failure lose the one copy of the session that still
     * genuinely existed.
     */
    public function test_a_regenerate_writes_the_replacement_before_destroying_the_old_id(): void
    {
        $oldId = \str_repeat('d', 32);
        $store = new RecordingSessionStore();
        $store->seed($oldId, ['user' => 42]);

        $session = new Session($store, $oldId);
        $session->regenerate();
        $newId = $session->id();
        $session->commit(60);

        self::assertSame([['write', $newId], ['destroy', $oldId]], $store->operations);
    }

    /**
     * If the store's destroy() call itself fails partway through commit()
     * — a real, disclosed possibility, not hypothetical — the replacement
     * data written just before it is not lost: it already survived its
     * own, independent write() call by the time destroy() ever runs.
     */
    public function test_a_failing_destroy_during_commit_after_regenerate_still_leaves_the_replacement_written(): void
    {
        $oldId = \str_repeat('d', 32);
        $store = new RecordingSessionStore(throwOnDestroy: new RuntimeException('store unavailable'));
        $store->seed($oldId, ['user' => 42]);

        $session = new Session($store, $oldId);
        $session->regenerate();
        $newId = $session->id();

        try {
            $session->commit(60);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('store unavailable', $e->getMessage());
        }

        self::assertSame([[$newId, ['user' => 42]]], $store->writes, 'the replacement was durably written before the failing destroy() ran.');
    }

    /**
     * The opposite failure: if the *write* fails, destroy() must never
     * run at all — the old, still-genuine session is left exactly as it
     * was, not already gone by the time the caller learns anything failed.
     */
    public function test_a_failing_write_during_commit_after_regenerate_leaves_the_old_persisted_session_intact(): void
    {
        $oldId = \str_repeat('d', 32);
        $store = new RecordingSessionStore(throwOnWrite: new RuntimeException('store unavailable'));
        $store->seed($oldId, ['user' => 42]);

        $session = new Session($store, $oldId);
        $session->regenerate();

        try {
            $session->commit(60);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('store unavailable', $e->getMessage());
        }

        self::assertSame([], $store->destroys, 'a failed write must never be followed by destroying the old id.');
        self::assertSame(['user' => 42], $store->read($oldId), 'the old, still-genuine session must remain readable.');
    }

    /**
     * destroy() only ever changes in-memory state — a store that throws
     * on every destroy() call proves this the same way as regenerate()'s
     * own equivalent test: nothing in destroy() ever calls the store.
     */
    public function test_destroy_never_touches_the_store_itself(): void
    {
        $knownId = \str_repeat('e', 32);
        $store = new RecordingSessionStore(new RuntimeException('store unavailable'));
        $store->seed($knownId, ['user' => 42]);

        $session = new Session($store, $knownId);
        $session->destroy();

        self::assertTrue($session->isDestroyed());
        self::assertSame([], $store->destroys, 'destroy() itself must never call the store.');
    }

    /**
     * If commit() itself fails removing the persisted entry, the
     * session's real storage is left exactly as it was — read() against
     * the same store afterward still finds the original data, and a
     * fresh Session pointed at the same id resolves it normally, not as
     * torn down.
     */
    public function test_a_failing_destroy_during_commit_after_destroy_propagates_and_leaves_the_session_intact(): void
    {
        $knownId = \str_repeat('e', 32);
        $store = new RecordingSessionStore(new RuntimeException('store unavailable'));
        $store->seed($knownId, ['user' => 42]);

        $session = new Session($store, $knownId);
        $session->destroy();

        try {
            $session->commit(60);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('store unavailable', $e->getMessage());
        }

        self::assertSame(['user' => 42], $store->read($knownId), 'a failed commit() must leave the real, stored session untouched.');
    }

    /**
     * Regenerating twice in one request must still only ever clean up the
     * one real artifact the session started with — the id from the first
     * regenerate() was never itself written to the store (writes are
     * deferred to commit()), so there is nothing under it to destroy.
     */
    public function test_repeated_regenerate_only_destroys_the_originally_persisted_id_once(): void
    {
        $originalId = \str_repeat('f', 32);
        $store = new RecordingSessionStore();
        $store->seed($originalId, ['user' => 42]);

        $session = new Session($store, $originalId);
        $session->regenerate();
        $intermediateId = $session->id();
        $session->regenerate();
        $finalId = $session->id();
        $session->commit(60);

        self::assertNotSame($intermediateId, $finalId);
        self::assertSame([$originalId], $store->destroys, 'only the one id that was ever genuinely persisted gets destroyed.');
        self::assertSame([[$finalId, ['user' => 42]]], $store->writes, 'only the final id is ever written.');
    }

    /**
     * regenerate() followed by destroy() in the same request: the
     * mid-request regenerated id was never persisted, so destroy() (which
     * always wins over any pending write) only has the one genuinely
     * stored, original id to clean up.
     */
    public function test_regenerate_then_destroy_destroys_only_the_originally_persisted_id(): void
    {
        $originalId = \str_repeat('f', 32);
        $store = new RecordingSessionStore();
        $store->seed($originalId, ['user' => 42]);

        $session = new Session($store, $originalId);
        $session->regenerate();
        $session->destroy();
        $session->commit(60);

        self::assertSame([$originalId], $store->destroys);
        self::assertSame([], $store->writes, 'a destroyed session never writes, regardless of an earlier regenerate().');
    }

    /**
     * The "no resurrection" half of destroy()'s own contract: a mutation
     * after destroy() marks the session dirty again, but commit() checks
     * isDestroyed() first, so that mutation is never actually persisted —
     * only the original persisted id is removed.
     */
    public function test_destroy_then_mutate_does_not_resurrect_the_session(): void
    {
        $knownId = \str_repeat('e', 32);
        $store = new RecordingSessionStore();
        $store->seed($knownId, ['user' => 42]);

        $session = new Session($store, $knownId);
        $session->destroy();
        $session->set('new', 'value');
        $session->commit(60);

        self::assertSame([], $store->writes, 'a mutation after destroy() must never be written.');
        self::assertSame([$knownId], $store->destroys);
    }

    /**
     * A fabricated/expired presented id was never actually found in the
     * store (see load()'s own rejection branch), so destroying it would
     * be a no-op at best — this proves it is never even attempted.
     */
    public function test_destroy_on_a_fabricated_id_never_calls_store_destroy(): void
    {
        $store = new RecordingSessionStore();

        $session = new Session($store, self::CHOSEN_ID);
        $session->destroy();

        self::assertTrue($session->commit(60), 'a destroyed session still needs its cookie expired.');
        self::assertSame([], $store->destroys, 'nothing was ever persisted under a fabricated id, so nothing needs destroying.');
    }

    /**
     * A brand-new, no-cookie session destroyed before anything else ever
     * touched it: nothing was ever persisted, so commit() has nothing to
     * remove — only the client-side cookie needs expiring.
     */
    public function test_destroy_on_a_new_session_never_calls_store_destroy(): void
    {
        $store = new RecordingSessionStore();

        $session = new Session($store, null);
        $session->destroy();

        self::assertTrue($session->commit(60));
        self::assertSame([], $store->destroys);
        self::assertSame([], $store->writes);
    }

    public function test_a_new_session_gets_a_wellformed_id(): void
    {
        $session = new Session($this->store, null);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->id());
    }

    /**
     * Requesting the id is an explicit request for a stable identity —
     * unlike get()/has() on the same fresh session, which stay lazy, it
     * must mark the session for commit even though nothing else was set.
     */
    public function test_id_on_a_new_session_marks_it_for_commit(): void
    {
        $session = new Session($this->store, null);
        $session->id();

        self::assertTrue($session->commit(60), 'id() alone must be enough to need a cookie.');
    }

    public function test_id_on_a_new_session_persists_an_empty_payload_a_second_session_can_read(): void
    {
        $first = new Session($this->store, null);
        $id = $first->id();
        $first->commit(60);

        $second = new Session($this->store, $id);
        self::assertSame($id, $second->id());
        self::assertSame([], $second->all());
    }

    /**
     * A second request presenting the id id() established must resolve
     * it without rewriting anything — the exact "does not re-persist"
     * half of the contract, proven at the store level via a real write
     * count rather than only through commit()'s own boolean return.
     */
    public function test_a_second_session_with_the_established_id_does_not_recommit(): void
    {
        $store = new RecordingSessionStore();
        $first = new Session($store, null);
        $id = $first->id();
        $first->commit(60);
        self::assertCount(1, $store->writes);

        $second = new Session($store, $id);
        self::assertSame($id, $second->id());
        self::assertFalse($second->commit(60), 'Resolving an already-established id must need no new cookie.');
        self::assertCount(1, $store->writes, 'A second, read-only id() must not trigger a second write.');
    }

    public function test_id_called_twice_on_a_new_session_still_commits_exactly_once(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, null);

        $first = $session->id();
        $second = $session->id();
        self::assertSame($first, $second);

        self::assertTrue($session->commit(60));
        self::assertCount(1, $store->writes);
    }

    /**
     * Distinct from id() above: a read-only get()/has() on a fresh,
     * no-cookie session is ordinary lazy access to data that plainly
     * isn't there yet, and must retain the existing no-store/no-cookie
     * behavior — it never establishes an identity on its own.
     */
    public function test_get_on_a_new_session_remains_lazy_and_uncommitted(): void
    {
        $session = new Session($this->store, null);

        self::assertNull($session->get('missing'));
        self::assertFalse($session->commit(60));
    }

    public function test_has_on_a_new_session_remains_lazy_and_uncommitted(): void
    {
        $session = new Session($this->store, null);

        self::assertFalse($session->has('missing'));
        self::assertFalse($session->commit(60));
    }

    /**
     * A wellformed cookie id the store has never heard of — fabricated,
     * or genuinely expired — is exactly what a session-fixation attempt
     * looks like: SessionMiddleware only filters malformed values, so
     * this is the first point that can tell "wellformed" apart from
     * "real." Every one of these proves a distinct part of the required
     * behavior, not just that the id changes.
     */
    private const string CHOSEN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_a_chosen_unknown_id_is_read_exactly_once(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $session->get('anything');

        self::assertSame([self::CHOSEN_ID], $store->reads);
    }

    public function test_first_access_to_an_unknown_id_rotates_to_a_different_wellformed_id(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $session->get('anything');

        self::assertNotSame(self::CHOSEN_ID, $session->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->id());
    }

    public function test_writing_to_an_unknown_id_never_writes_the_chosen_id_and_writes_the_fresh_one(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $session->set('secret', 'value');
        $session->commit(60);

        self::assertCount(1, $store->writes, 'exactly one write, not a write per accessor call');
        [$writtenId, $writtenData] = $store->writes[0];
        self::assertNotSame(self::CHOSEN_ID, $writtenId);
        self::assertSame($session->id(), $writtenId);
        self::assertSame(['secret' => 'value'], $writtenData);

        foreach ($store->writes as [$id]) {
            self::assertNotSame(self::CHOSEN_ID, $id, 'the chosen id must never be the target of a write');
        }
    }

    public function test_commit_requests_a_cookie_after_rotating_an_unknown_id(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $session->set('secret', 'value');

        self::assertTrue($session->commit(60));
    }

    /**
     * Requesting the id is itself session use — a client that presents
     * an unknown id and only ever calls id() (never set()/get()) must
     * still get back a fresh, unexposed id, and that identity must
     * actually be persisted rather than left to silently vanish once
     * the request ends.
     */
    public function test_id_alone_never_exposes_the_chosen_id_and_the_result_is_persisted(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $freshId = $session->id();

        self::assertNotSame(self::CHOSEN_ID, $freshId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $freshId);

        self::assertTrue($session->commit(60), 'the fresh identity must be persisted, not silently dropped');
        self::assertCount(1, $store->writes);
        self::assertSame($freshId, $store->writes[0][0]);

        foreach ($store->reads as $id) {
            self::assertNotSame($freshId, $id, 'the fresh id was minted this request, so nothing could have read it yet');
        }
    }

    public function test_an_untouched_session_with_an_unknown_cookie_performs_no_reads_or_writes(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        self::assertFalse($session->commit(60));
        self::assertSame([], $store->reads);
        self::assertSame([], $store->writes);
        self::assertFalse($session->wasLoaded());
    }

    /**
     * KINETIS-70: distinct from the untouched case directly above — this
     * one genuinely accesses the session (get() does call load(), which
     * does read the store and does rotate the id), but a plain read
     * against a rejected cookie must still cost no write and need no
     * cookie. Before this fix, load() itself marked the session dirty
     * the moment it saw a rejected cookie, so even this read-only access
     * would have persisted a fresh, empty session — the amplification
     * path this fix closes, reproduced here at the lowest level that can
     * show it: a genuine load() call that finds nothing, with no
     * mutating accessor anywhere in the request.
     */
    public function test_a_read_only_access_to_an_unknown_cookie_id_remains_lazy_and_uncommitted(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        self::assertNull($session->get('anything'));
        self::assertSame([self::CHOSEN_ID], $store->reads, 'the rejected id is still genuinely read once.');
        self::assertFalse($session->commit(60), 'a read against a rejected cookie must not itself persist anything.');
        self::assertSame([], $store->writes);
    }

    /**
     * KINETIS-70's exact scenario: an attacker submits a CSRF token —
     * right or wrong doesn't matter here, since a rejected cookie's
     * session has no real token to match against regardless — against a
     * cookie the store has never heard of. Checking it must never itself
     * allocate and persist a session; repeating this request any number
     * of times must never grow the store.
     */
    public function test_verify_csrf_token_on_an_unknown_cookie_id_remains_lazy_and_uncommitted(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        self::assertFalse($session->verifyCsrfToken('wrong-token'));
        self::assertFalse($session->commit(60), 'checking a token against a rejected cookie must not itself persist anything.');
        self::assertSame([], $store->writes);
    }

    /**
     * The other half of the fix: making the rotation lazy must not break
     * what it was lazy *around* — id() still explicitly requests a
     * stable identity, and must still get one persisted under the
     * already-rotated fresh id, exactly as before this fix, just no
     * longer as a side effect of load() alone.
     */
    public function test_id_on_an_unknown_cookie_id_still_marks_it_for_commit(): void
    {
        $store = new RecordingSessionStore();
        $session = new Session($store, self::CHOSEN_ID);

        $freshId = $session->id();

        self::assertNotSame(self::CHOSEN_ID, $freshId);
        self::assertTrue($session->commit(60), 'id() must still establish and persist a stable identity.');
        self::assertSame([[$freshId, []]], $store->writes);
    }

    /**
     * KINETIS-70: the amplification path closed by this fix is about
     * sessions that don't exist yet — this proves the opposite direction
     * holds too. A genuinely existing session, presented with the wrong
     * CSRF token, must be left completely untouched: not destroyed, not
     * rewritten, not even re-cookied. The store's own entry for it is
     * read back directly afterward, not inferred from commit()'s return
     * value alone.
     */
    public function test_verify_csrf_token_on_a_genuinely_existing_session_with_a_wrong_token_does_not_mutate_it(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('9', 32);
        $store->seed($knownId, ['user' => 42, '_csrf' => 'the-real-token']);

        $session = new Session($store, $knownId);

        self::assertFalse($session->verifyCsrfToken('wrong-token'));
        self::assertFalse($session->commit(60), 'a mismatch against a real session must not refresh its cookie either.');
        self::assertSame([], $store->writes);
        self::assertSame([], $store->destroys);
        self::assertSame(
            ['user' => 42, '_csrf' => 'the-real-token'],
            $store->read($knownId),
            'the real session must remain exactly as it was.',
        );
    }

    /**
     * KINETIS-70 FEEDBACK: the sharper version of the test directly
     * above — a genuinely existing session with pending flash data.
     * verifyCsrfToken() calls load(), and load() marks a session dirty
     * whenever flash data is present, entirely independent of anything
     * CSRF-related — a real, pre-existing design for the ordinary case
     * (reading is itself what ages a flash value, so its removal has to
     * be persisted even if nothing else changes). A rejected token must
     * not ride along on that: checking it must never itself schedule
     * the ordinary aging a real access would, so the flash generation
     * is still there, completely unaged, afterward.
     */
    public function test_verify_csrf_token_on_a_genuinely_existing_session_with_pending_flash_data_and_a_wrong_token_does_not_age_it(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('8', 32);
        $store->seed($knownId, ['user' => 42, '_csrf' => 'the-real-token', '_flash.old' => ['status' => 'saved']]);

        $session = new Session($store, $knownId);

        self::assertFalse($session->verifyCsrfToken('wrong-token'));
        self::assertFalse($session->commit(60), 'a mismatch must not persist the ordinary flash-aging a real access would.');
        self::assertSame([], $store->writes);
        self::assertSame([], $store->destroys);
        self::assertSame(
            ['user' => 42, '_csrf' => 'the-real-token', '_flash.old' => ['status' => 'saved']],
            $store->read($knownId),
            'the flash generation must still be there, completely unaged.',
        );
    }

    /**
     * KINETIS-70 FEEDBACK 2: the other half of the same fix — a
     * *successful* verification must itself schedule the ordinary
     * flash-aging a real access needs, immediately, not merely once
     * (and only if) something else later happens to touch the session
     * too. A guarded handler that never otherwise reads/writes Session
     * at all must still see its pending flash correctly aged, so the
     * success path promotes the exact snapshot it already read into
     * the session's real loaded state right away — no second store
     * read, confirmed directly here, not just inferred from behavior.
     */
    public function test_verify_csrf_token_on_a_genuinely_existing_session_with_pending_flash_data_and_the_real_token_ages_it_immediately(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('7', 32);
        $store->seed($knownId, ['user' => 42, '_csrf' => 'the-real-token', '_flash.old' => ['status' => 'saved']]);

        $session = new Session($store, $knownId);

        self::assertTrue($session->verifyCsrfToken('the-real-token'));
        self::assertTrue($session->commit(60), 'a successful check must itself schedule the ordinary flash-aging, with nothing else ever touching Session.');
        self::assertSame([$knownId], $store->reads, 'verification and the resulting commit must share the one read that already confirmed the token.');
        self::assertArrayNotHasKey('_flash.old', $store->read($knownId) ?? [], 'the aged generation must actually be gone from the store.');
    }

    /**
     * The other half of the read-count proof directly above: reading a
     * value via flashed() *after* a successful verification must not
     * cost a second store read either — the accessor sees the same
     * already-promoted state verifyCsrfToken() itself established.
     */
    public function test_verify_csrf_token_success_promotes_state_a_later_accessor_reuses_without_a_second_read(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('6', 32);
        $store->seed($knownId, ['user' => 42, '_csrf' => 'the-real-token', '_flash.old' => ['status' => 'saved']]);

        $session = new Session($store, $knownId);

        self::assertTrue($session->verifyCsrfToken('the-real-token'));
        self::assertSame('saved', $session->flashed('status'), 'a later accessor must see the exact snapshot verification already promoted.');
        self::assertSame([$knownId], $store->reads, 'flashed() must not trigger a second read once verification already promoted the session.');
    }

    /**
     * The counterpart proving the fix is scoped correctly: a genuinely
     * stored id — including one whose stored payload is an empty array
     * — is left exactly as presented. Only an unknown id gets rotated.
     */
    public function test_a_read_only_access_to_a_genuinely_stored_id_remains_stable(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('b', 32);
        $store->seed($knownId, ['user' => 42]);

        $session = new Session($store, $knownId);
        self::assertSame(42, $session->get('user'));

        self::assertSame($knownId, $session->id());
        self::assertFalse($session->commit(60), 'a genuine, read-only hit needs no rewrite and no new cookie');
        self::assertSame([], $store->writes);
    }

    public function test_a_genuinely_stored_id_with_an_empty_payload_remains_valid(): void
    {
        $store = new RecordingSessionStore();
        $knownId = \str_repeat('c', 32);
        $store->seed($knownId, []);

        $session = new Session($store, $knownId);

        self::assertSame($knownId, $session->id());
        self::assertFalse($session->has('anything'));
        self::assertSame($knownId, $session->id(), 'a real, empty session is still a real session — not rotated');
        self::assertFalse($session->commit(60));
        self::assertSame([], $store->writes);
    }
}
