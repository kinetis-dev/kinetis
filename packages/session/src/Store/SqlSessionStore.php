<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Support\SessionExpiry;

/**
 * Sessions in a `kinetis_sessions` table, over the persistence SQL
 * contracts — the same dialect-agnostic surface SqlMigrationRepository
 * uses, since every statement here is plain standard SQL. The table is
 * never auto-created: ready-to-copy migration stubs ship under
 * resources/migrations/, the same convention as kinetis/queue's jobs
 * table.
 *
 * Expiry is an `expires_at` column, filtered on read — the row itself
 * stays until `gc()` deletes it. Schedule the `session:gc` command for
 * that; nothing runs it implicitly. A session is live only while
 * `expires_at` is strictly in the future (`expires_at > now`); `gc()`
 * deletes the exact complement (`expires_at <= now`) — the same
 * boundary {@see FileSessionStore} enforces, so both stores agree on
 * the exact second a session expires. The absolute timestamp itself
 * comes from {@see SessionExpiry::timestampFor()} — never `time() +
 * $lifetimeSeconds` directly — so an invalid `$lifetimeSeconds` fails
 * with a package exception rather than a raw `TypeError` out of
 * formatTimestamp().
 *
 * `expires_at` must be a *timezone-naive* column type — MySQL's
 * `DATETIME`, or Postgres's `TIMESTAMP` (without time zone, the
 * default) — never MySQL's own `TIMESTAMP`, which reinterprets a bound
 * literal through the connection's session timezone. Every value this
 * class binds is a bare `gmdate()`-formatted UTC wall-clock string with
 * no embedded offset, which a timezone-naive column stores exactly as
 * given, making the comparison against `self::now()`'s identically
 * formatted string correct whatever the connection's session timezone
 * is. This class never changes that setting: a shared application
 * connection's session state is not this package's to mutate, so the
 * column type is what keeps the ambient setting out of the comparison.
 */
final readonly class SqlSessionStore implements SessionStoreInterface, GarbageCollectableStoreInterface
{
    private const string TABLE = 'kinetis_sessions';

    public function __construct(private SqlLink $db) {}

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $row = $this->db
            ->execute('SELECT payload FROM ' . self::TABLE . ' WHERE id = ? AND expires_at > ?', [$id, self::now()])
            ->fetchRow();

        if ($row === null || !\is_string($row['payload'] ?? null)) {
            return null;
        }

        $data = \json_decode($row['payload'], true);

        /** @var ?array<string, mixed> */
        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function create(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (id, payload, expires_at) VALUES (?, ?, ?)',
            [$id, self::encode($data), self::expiresAt($lifetimeSeconds)],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function update(string $id, array $data, int $lifetimeSeconds): bool
    {
        $payload = self::encode($data);
        $expiresAt = self::expiresAt($lifetimeSeconds);

        $updated = $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET payload = ?, expires_at = ? WHERE id = ? AND expires_at > ?',
            [$payload, $expiresAt, $id, self::now()],
        )->getRowCount();

        if ($updated !== null && $updated > 0) {
            return true;
        }

        // MySQL counts changed rows, not matched ones, so it reports 0
        // for an UPDATE whose values equal the stored row byte for byte
        // — the ordinary case of a request that read a session and
        // changed nothing in it. Zero therefore does not prove the row
        // is gone, and neither does a driver that cannot count at all.
        // One existence check against the same live-row condition
        // settles which it was.
        return $this->db
            ->execute(
                'SELECT id FROM ' . self::TABLE . ' WHERE id = ? AND expires_at > ?',
                [$id, self::now()],
            )
            ->fetchRow() !== null;
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }

    #[\Override]
    public function gc(): int
    {
        return $this->db
            ->execute('DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?', [self::now()])
            ->getRowCount() ?? 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }

    private static function expiresAt(int $lifetimeSeconds): string
    {
        return self::formatTimestamp(SessionExpiry::timestampFor($lifetimeSeconds));
    }

    private static function now(): string
    {
        return self::formatTimestamp(\time());
    }

    private static function formatTimestamp(int $unix): string
    {
        return \gmdate('Y-m-d H:i:s', $unix);
    }
}
