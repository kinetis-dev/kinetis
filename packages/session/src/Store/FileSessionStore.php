<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;

/**
 * One JSON file per session under a single directory, needing no
 * backing service at all — suited to local development. Expiry is an
 * `expiresAt` timestamp inside the envelope, checked on read; an
 * expired file is deleted when next read, and gc() sweeps the rest —
 * schedule the `session:gc` command for that, nothing runs it
 * implicitly.
 *
 * Multi-process safe only in the last-write-wins sense the store
 * contract already declares; not intended for production fleets, where
 * every worker would need the same shared filesystem anyway.
 */
final readonly class FileSessionStore implements SessionStoreInterface, GarbageCollectableStoreInterface
{
    public function __construct(private string $directory)
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new SessionException("Session directory \"{$directory}\" could not be created.");
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $path = $this->pathFor($id);
        $raw = @\file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $envelope = \json_decode($raw, true);

        if (!\is_array($envelope) || !\is_array($envelope['data'] ?? null) || !\is_int($envelope['expiresAt'] ?? null)) {
            return null;
        }

        if ($envelope['expiresAt'] < \time()) {
            @\unlink($path);

            return null;
        }

        /** @var array<string, mixed> */
        return $envelope['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $payload = \json_encode(
            ['expiresAt' => \time() + $lifetimeSeconds, 'data' => $data],
            JSON_THROW_ON_ERROR,
        );

        $path = $this->pathFor($id);
        // Same directory as $path, not sys_get_temp_dir(): rename() is
        // only atomic within one filesystem, and writing straight to
        // $path (even under LOCK_EX, which only ever protected against
        // another *writer*) lets a concurrent, lock-free read() observe
        // a truncated/partial file mid-write — a torn read, not covered
        // by "last-write-wins". Write-then-rename is what this
        // project's own AOT CacheStore already does for the same
        // reason; a session file additionally gets 0600, matching this
        // store's own 0700 directory, since it's not meant to be
        // group/world-readable the way the AOT cache is.
        //
        // Named ".sess-tmp-*", deliberately never matching gc()'s own
        // "sess_*" glob pattern — the earlier "$path.<random>.tmp" naming
        // still started with "sess_", so a gc() sweep landing between
        // this file_put_contents() and the rename() below could collect
        // and unlink the in-progress temp file out from under this write
        // (a partial envelope is always collectable; a complete one that
        // just hasn't been renamed yet still looks collectable too, since
        // gc() has no way to tell "in progress" from "abandoned"), making
        // the rename() below fail and silently losing the write.
        $tmpPath = $this->directory . '/.sess-tmp-' . \bin2hex(\random_bytes(8)) . '.tmp';

        if (@\file_put_contents($tmpPath, $payload) === false) {
            // file_put_contents() can create a partial file before
            // failing (e.g. the disk fills up mid-write) — clean it up
            // rather than leaving a stray temp file behind.
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be written.");
        }

        @\chmod($tmpPath, 0600);

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);

            throw new SessionException("Session file for \"{$id}\" could not be written.");
        }
    }

    #[\Override]
    public function destroy(string $id): void
    {
        @\unlink($this->pathFor($id));
    }

    #[\Override]
    public function gc(): int
    {
        $removed = 0;

        foreach (\glob($this->directory . '/sess_*') ?: [] as $path) {
            if (self::isCollectable($path) && @\unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** Expired, or unreadable as a session envelope at all. */
    private static function isCollectable(string $path): bool
    {
        $raw = @\file_get_contents($path);
        $envelope = $raw === false ? null : \json_decode($raw, true);

        return !\is_array($envelope)
            || !\is_int($envelope['expiresAt'] ?? null)
            || $envelope['expiresAt'] < \time();
    }

    private function pathFor(string $id): string
    {
        // Ids are validated by the middleware before ever reaching a
        // store, but a filesystem path is built here, so this store
        // guards on its own too — defense in depth against traversal.
        if (\preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new SessionException('Invalid session id.');
        }

        return $this->directory . '/sess_' . $id;
    }
}
