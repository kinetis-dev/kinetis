<?php

declare(strict_types=1);

namespace Kinetis\Session;

/**
 * The per-request session a controller works with — registered on the
 * request's own RequestScope by {@see Middleware\SessionMiddleware}, so
 * constructor-injecting `Session` is all a controller does:
 *
 *     public function __construct(private Session $session) {}
 *
 *     $this->session->set('theme', 'dark');
 *     $this->session->get('theme');
 *
 * Loading is lazy: the store isn't touched until something actually
 * reads or writes, so a route that never uses its session costs no
 * storage round trip and sets no cookie.
 *
 * A presented cookie id is verified against the store, not trusted
 * because it happens to be wellformed. {@see Middleware\SessionMiddleware}
 * only filters out malformed cookie values before construction; on the
 * first real access, this class reads the id it was actually given and,
 * if the store has no matching entry (fabricated, or genuinely expired),
 * rotates to a fresh id before any state can be exposed or written —
 * the client never gets to choose the identifier a session ends up
 * stored under. A genuinely stored id, including one whose payload is
 * an empty array, is left exactly as presented.
 *
 * That rotation itself is lazy, the same as a brand-new no-cookie
 * session: it changes only in-memory state, and persists nothing unless
 * something afterward actually needs it to — a mutation, an explicit
 * id() request, or generating a CSRF token via csrfToken(). A read-only
 * check against a rejected cookie (get()/has()/verifyCsrfToken()) still
 * performs the one genuine store read needed to learn the cookie is
 * unknown — unlike the identical check against a brand-new session with
 * no cookie at all, which touches the store not at all — but writes and
 * sets no cookie either way. This is what closes a real amplification
 * path: a submitted-but-wrong CSRF token, checked against any cookie
 * the store has never heard of, must never itself be what allocates and
 * persists a session record. verifyCsrfToken() reads a genuinely
 * existing session's own stored token the same lazy way — a *mismatch*
 * against a real session must leave its data, flash generations, TTL,
 * and cookie exactly as they were, not merely avoid creating a new
 * one; a *match* immediately enters the session's normal loaded state,
 * flash-generation-aging included, rather than only if some later
 * accessor happens to touch it too — see verifyCsrfToken()'s own
 * docblock for exactly how.
 *
 * Flash data (`flash()`) survives exactly one subsequent request — set
 * during this one, readable during the next, gone after that. The
 * classic post-redirect-get companion.
 *
 * `regenerate()` gives the session a fresh id while keeping its data —
 * call it whenever privilege changes (login above all), so a session id
 * fixated before authentication never carries into an authenticated
 * session.
 *
 * regenerate() and destroy() only ever change in-memory state — neither
 * touches the store. The store is only ever written to from commit(),
 * which {@see Middleware\SessionMiddleware} calls after the handler has
 * returned successfully: a request that throws before then leaves both
 * the store and the browser's cookie exactly as they were, since neither
 * side was ever asked to change. commit() itself orders its own store
 * calls so a mid-commit failure can never lose recoverable data: a
 * regenerated id's replacement data is written before its old id is
 * destroyed, and a destroyed session's storage is removed before the
 * response ever claims the cookie is gone.
 */
final class Session
{
    private const string FLASH_NEW = '_flash.new';

    private const string FLASH_OLD = '_flash.old';

    /** @var ?array<string, mixed> */
    private ?array $data = null;

    private bool $dirty = false;

    private bool $loaded = false;

    private bool $destroyed = false;

    private string $id;

    private readonly ?string $initialId;

    /**
     * The id, if any, currently holding this session's real data in the
     * store — set once, by load(), from $initialId, but only when a real
     * read() actually found something there. Distinct from $initialId
     * itself: a fabricated/expired cookie id is still $initialId (needed
     * by commit()'s "does the client's cookie already match?" check) but
     * was never found in the store, so nothing needs destroying under
     * it. regenerate() rotates $id away without touching this field, so
     * repeated regenerate() calls in one request still only ever clean
     * up the one real artifact this session started with — never an
     * in-memory-only id nothing has been written under yet.
     */
    private ?string $persistedId = null;

    public function __construct(
        private readonly SessionStoreInterface $store,
        ?string $cookieId,
    ) {
        $this->initialId = $cookieId;
        $this->id = $cookieId ?? self::generateId();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function has(string $key): bool
    {
        $this->load();

        return \array_key_exists($key, $this->data ?? []);
    }

    public function remove(string $key): void
    {
        $this->load();

        if (\array_key_exists($key, $this->data ?? [])) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Everything stored, minus this class's own bookkeeping keys.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->load();
        $data = $this->data ?? [];
        unset($data[self::FLASH_NEW], $data[self::FLASH_OLD], $data['_csrf']);

        return $data;
    }

    /** Stores $value for this request and the next one only. */
    public function flash(string $key, mixed $value): void
    {
        $this->load();
        $this->data[self::FLASH_NEW][$key] = $value;
        $this->dirty = true;
    }

    /** Reads a value flashed during the previous request. */
    public function flashed(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->data[self::FLASH_OLD][$key] ?? $default;
    }

    /**
     * The CSRF token bound to this session, generated on first use.
     * {@see Middleware\CsrfMiddleware} compares submitted tokens against
     * this value.
     */
    public function csrfToken(): string
    {
        $this->load();

        if (!isset($this->data['_csrf']) || !\is_string($this->data['_csrf'])) {
            $this->data['_csrf'] = \bin2hex(\random_bytes(20));
            $this->dirty = true;
        }

        return $this->data['_csrf'];
    }

    /**
     * Constant-time comparison against whatever CSRF token this session
     * already has — never generates one. {@see Middleware\CsrfMiddleware}
     * uses this instead of csrfToken(), specifically so that checking a
     * wrong token can never itself be what creates one — or, for a
     * session that doesn't exist in the store yet, what allocates and
     * persists one merely to have something to compare against. A
     * session with no CSRF token at all (nothing ever called csrfToken()
     * to render a form) has nothing to match, so any submitted value is
     * rejected — the safe default for a session that was never
     * bootstrapped with one. (`$submitted` is a plain `string`, never
     * `null` — an absent token is {@see Middleware\CsrfMiddleware}'s own
     * concern, handled before this method is ever called.)
     *
     * A mismatch leaves the session completely inert — $loaded stays
     * false, nothing is scheduled, matching every other rejected-check
     * behavior in this class. A match promotes the exact snapshot this
     * method itself already read into the session's real, loaded
     * state — data, persistedId, and the same flash-generation-aging
     * load() would schedule — immediately, not merely once (and only
     * if) something else later happens to touch the session too:
     * "a following request still sees flash data" would otherwise
     * silently depend on the guarded handler's own, unrelated
     * behavior. The read that already confirmed the token is reused
     * rather than repeated: a second, later read (via a real load()
     * call this promotion makes unnecessary) could in principle observe
     * different session data than what was actually verified, under
     * concurrent modification of the same session — reusing the one
     * snapshot both verifies against and promotes is what closes that
     * gap, not just what avoids the extra round trip.
     */
    public function verifyCsrfToken(string $submitted): bool
    {
        if ($this->loaded) {
            $stored = $this->data['_csrf'] ?? null;

            return \is_string($stored) && \hash_equals($stored, $submitted);
        }

        if ($this->initialId === null) {
            return false;
        }

        $snapshot = $this->store->read($this->initialId);
        $stored = $snapshot['_csrf'] ?? null;

        if (!\is_string($stored) || !\hash_equals($stored, $submitted)) {
            return false;
        }

        $this->loaded = true;
        $this->data = $snapshot;
        $this->persistedId = $this->initialId;

        if (isset($this->data[self::FLASH_OLD]) || isset($this->data[self::FLASH_NEW])) {
            $this->dirty = true;
        }

        return true;
    }

    /**
     * A fresh id, same data — the session-fixation defense. The old id's
     * stored payload is destroyed at commit() time, once the replacement
     * has been durably written under the new id — never here, and never
     * before that replacement write succeeds, so a captured pre-auth id
     * only ever stops working once the fresh one is guaranteed usable.
     */
    public function regenerate(): void
    {
        $this->load();
        $this->id = self::generateId();
        $this->dirty = true;
    }

    /**
     * Drops the data; the stored payload is removed and the cookie
     * expired at commit() time. Any access after this call still reads
     * the now-empty in-memory data, and a subsequent set()/flash()/etc.
     * marks the session dirty again — but commit() checks isDestroyed()
     * first and always wins, so nothing written after destroy() can ever
     * be persisted, or "resurrect" a session already on its way out.
     */
    public function destroy(): void
    {
        $this->load();
        $this->data = [];
        $this->destroyed = true;
        $this->dirty = false;
    }

    /**
     * Requesting the id is itself session use — verified the same way
     * every other accessor is, via {@see load()}, so this can never hand
     * back a client-presented id the store has never heard of.
     *
     * For a genuinely new, no-cookie session, or one whose presented
     * cookie was rejected, load() alone leaves the session lazy — a
     * read-only get()/has()/verifyCsrfToken() on either persists
     * nothing and sets no cookie (though a rejected cookie's own read
     * still genuinely reaches the store, to learn it's unknown) — but a
     * caller asking for the id has explicitly requested a
     * stable identity, and handing one back that then dies unpersisted
     * at the end of the request would make id() unreliable for exactly
     * what it's for. Marking dirty here, and only here, establishes
     * that identity: commit() will write the (possibly still-empty)
     * payload and report a cookie is needed, without changing behavior
     * for every other accessor.
     *
     * Checked via $persistedId, not $initialId — the two cases id()
     * needs to catch (no cookie at all, and a cookie the store has
     * never heard of) both leave $persistedId null after load(), while
     * $initialId is null only for the first; keying on $persistedId
     * catches both with one condition. A session with a genuinely
     * stored id ($persistedId !== null after load()) is unaffected.
     */
    public function id(): string
    {
        $this->load();

        if ($this->persistedId === null) {
            $this->dirty = true;
        }

        return $this->id;
    }

    /**
     * Persists when anything changed, aging flash data one generation.
     * Called by the middleware only after the handler has returned
     * successfully — this is the one place regenerate()/destroy()'s
     * store effects actually happen, so a request that throws before
     * this runs leaves the store exactly as it already was; not part of
     * what a controller needs to touch.
     *
     * A destroyed session always wins over dirty: even if something was
     * set() after destroy() was called, that data is never written —
     * only the original persisted id (if any) is removed.
     *
     * A regenerated id's replacement data is written before its old,
     * persisted id is destroyed — never the other way around — so a
     * failure destroying the old id (the store call itself throws)
     * still leaves the new id's data recoverable, and a failure writing
     * the new id's data leaves the old, still-genuine session untouched
     * rather than already gone.
     *
     * Every successful write reports true, even when the id itself is
     * unchanged — $lifetimeSeconds is counted from *this* write, and
     * SessionMiddleware's own cookie carries the browser-side half of
     * that same countdown (its Max-Age). Reporting false whenever the id
     * happened not to change would let the store's expiry keep advancing
     * on every mutation while the browser's cookie kept counting down
     * from whenever it was first issued — a session the store still
     * considers live going stale and getting silently discarded
     * client-side well before then. A request that never calls commit()
     * at all (an exception before SessionMiddleware reaches it) leaves
     * both the store and the cookie untouched, exactly as before.
     *
     * @return bool whether a cookie needs to be (re)written
     */
    public function commit(int $lifetimeSeconds): bool
    {
        if ($this->destroyed) {
            if ($this->persistedId !== null) {
                $this->store->destroy($this->persistedId);
                $this->persistedId = null;
            }

            return true;
        }

        if (!$this->dirty) {
            // Untouched, or read-only use of an existing session: nothing
            // to write, and the browser already holds the right cookie.
            // A flashed value from last request still ages out: reading
            // is load(), and load() shifting old flash marks dirty.
            return false;
        }

        $data = $this->data ?? [];

        // What was flashed this request becomes readable next request.
        if (isset($data[self::FLASH_NEW])) {
            $data[self::FLASH_OLD] = $data[self::FLASH_NEW];
            unset($data[self::FLASH_NEW]);
        } else {
            unset($data[self::FLASH_OLD]);
        }

        $this->store->write($this->id, $data, $lifetimeSeconds);

        if ($this->persistedId !== null && $this->persistedId !== $this->id) {
            $this->store->destroy($this->persistedId);
        }

        $this->persistedId = $this->id;

        return true;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function wasLoaded(): bool
    {
        return $this->loaded;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if ($this->initialId === null) {
            $this->data = [];

            return;
        }

        $stored = $this->store->read($this->initialId);

        if ($stored === null) {
            // The client presented a wellformed id (SessionMiddleware
            // only filters malformed ones) the store has never heard
            // of — fabricated, or genuinely expired. Writing anything
            // under an id the client already knows would let it read
            // back whatever gets stored there: the session-fixation
            // primitive this branch exists to close. Rotate to a fresh
            // id nothing external has ever seen, and treat this as a
            // genuine new session — but not, itself, a reason to
            // persist one (see this class's own docblock for why):
            // $persistedId is deliberately left null here, never set to
            // the fresh id, so the "no real backing row exists yet"
            // check both id() and commit() key on stays accurate.
            $this->id = self::generateId();
            $this->data = [];

            return;
        }

        $this->data = $stored;
        $this->persistedId = $this->initialId;

        // Flash values written last request were shifted to OLD at that
        // commit; anything still in OLD now has been readable for one
        // full request already and must not survive another commit. The
        // shift happens in commit(), so here the presence of OLD data
        // only means this request may read it — but its removal has to
        // be persisted even if nothing else changes.
        if (isset($this->data[self::FLASH_OLD]) || isset($this->data[self::FLASH_NEW])) {
            $this->dirty = true;
        }
    }

    private static function generateId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
