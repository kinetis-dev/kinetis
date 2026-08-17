<?php

declare(strict_types=1);

namespace Kinetis\Session;

/**
 * Where session payloads live between requests. Implementations deal in
 * plain `array<string, mixed>` data that must be JSON-serializable —
 * stores encode with JSON rather than PHP's native serialize(), so a
 * tampered or crafted payload can never become an object-injection
 * vector on read.
 *
 * Expiry is the store's own job: `read()` returns null for a session
 * that has passed its lifetime, however the backend tracks that (a TTL
 * the backend enforces itself, an embedded timestamp, a column).
 *
 * Concurrent requests sharing one session are last-write-wins — no
 * store locks, deliberately: locking would serialize a browser's
 * parallel requests against each other and fight the concurrent-worker
 * model this framework is built around. Session data should be small
 * and low-contention (an auth reference, a CSRF token, flash data), not
 * a shared mutable workspace.
 */
interface SessionStoreInterface
{
    /**
     * @return ?array<string, mixed> null when the session does not
     *     exist or has expired
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @param int $lifetimeSeconds how long the payload stays readable,
     *     counted from this write
     */
    public function write(string $id, array $data, int $lifetimeSeconds): void;

    public function destroy(string $id): void;
}
