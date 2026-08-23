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
 * Flash data (`flash()`) survives exactly one subsequent request — set
 * during this one, readable during the next, gone after that. The
 * classic post-redirect-get companion.
 *
 * `regenerate()` gives the session a fresh id while keeping its data —
 * call it whenever privilege changes (login above all), so a session id
 * fixated before authentication never carries into an authenticated
 * session.
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
     * A fresh id, same data — the session-fixation defense. The old id's
     * stored payload is destroyed so a captured pre-auth id stops working.
     */
    public function regenerate(): void
    {
        $this->load();
        $this->store->destroy($this->id);
        $this->id = self::generateId();
        $this->dirty = true;
    }

    /** Drops the data and the stored payload; the cookie gets expired. */
    public function destroy(): void
    {
        $this->load();
        $this->store->destroy($this->id);
        $this->data = [];
        $this->destroyed = true;
        $this->dirty = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Persists when anything changed, aging flash data one generation.
     * Called by the middleware after the handler ran; not part of what a
     * controller needs to touch.
     *
     * @return bool whether a cookie needs to be (re)written
     */
    public function commit(int $lifetimeSeconds): bool
    {
        if ($this->destroyed) {
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

        return $this->id !== $this->initialId;
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
        $this->data = $this->initialId !== null ? ($this->store->read($this->initialId) ?? []) : [];

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
