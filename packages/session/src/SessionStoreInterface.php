<?php

declare(strict_types=1);

namespace Kinetis\Session;

/**
 * Where session payloads live between requests. Implementations deal in
 * plain `array<string, mixed>` application data that must be
 * JSON-serializable: every store projects it to JSON, and `read()`
 * returns decoded data rather than a restored object graph.
 *
 * Expiry is the store's own job: `read()` returns null for a session
 * that has passed its lifetime, however the backend tracks that (a TTL
 * the backend enforces itself, an embedded timestamp, a column).
 *
 * Writing is split in two because removing a stored id is terminal: an
 * update may not bring back a record another request destroyed or
 * rotated away. `create()` establishes a new id; `update()` writes only
 * over a record that is still stored and still live, and reports false
 * when it is not.
 *
 * Concurrent updates of one live id are last-write-wins. There are no
 * store locks: locking would serialize a browser's parallel requests
 * against each other and fight the concurrent-worker model this
 * framework is built around. Session data should be small and
 * low-contention (an auth reference, a CSRF token, flash data), not a
 * shared mutable workspace.
 */
interface SessionStoreInterface
{
    /**
     * @return ?array<string, mixed> null when the session does not
     *     exist or has expired
     */
    public function read(string $id): ?array;

    /**
     * Stores $data under an id no record exists for yet.
     *
     * @param array<string, mixed> $data
     * @param int $lifetimeSeconds how long the payload stays readable,
     *     counted from this write
     */
    public function create(string $id, array $data, int $lifetimeSeconds): void;

    /**
     * Replaces the payload and restarts the lifetime of an id that is
     * still stored and still live.
     *
     * @param array<string, mixed> $data
     * @param int $lifetimeSeconds how long the payload stays readable,
     *     counted from this write
     * @return bool false when no live record exists to update, which
     *     makes this write a stale one to discard; an I/O or encoding
     *     failure throws instead
     */
    public function update(string $id, array $data, int $lifetimeSeconds): bool;

    public function destroy(string $id): void;
}
