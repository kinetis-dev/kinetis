<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Session\GarbageCollectableStoreInterface;
use Kinetis\Session\SessionStoreInterface;

/**
 * Sessions in a `kinetis_sessions` table, over the persistence SQL
 * contracts — the same dialect-agnostic surface SqlMigrationRepository
 * uses, since every statement here is plain standard SQL. The table is
 * never auto-created: ready-to-copy migration stubs ship under
 * resources/migrations/, the same convention as kinetis/queue's jobs
 * table.
 *
 * Expiry is an `expires_at` timestamp column, filtered on read — the
 * row itself stays until `gc()` deletes it. Schedule the `session:gc`
 * command for that; nothing runs it implicitly.
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
    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $payload = \json_encode($data, JSON_THROW_ON_ERROR);
        $expiresAt = self::formatTimestamp(\time() + $lifetimeSeconds);

        // Portable upsert without dialect-specific ON DUPLICATE KEY /
        // ON CONFLICT syntax: an UPDATE first, an INSERT when nothing
        // matched. Two traps this shape has to survive: MySQL reports 0
        // affected rows for an UPDATE whose values are byte-identical to
        // the stored row (so "0 updated" does not prove absence), and
        // two parallel first requests of a brand-new session can race
        // their INSERTs. Both resolve the same way — when the INSERT
        // hits the primary key, the row provably exists, and a repeat
        // UPDATE lands the write under this store's declared
        // last-write-wins model.
        $updateSql = 'UPDATE ' . self::TABLE . ' SET payload = ?, expires_at = ? WHERE id = ?';
        $updated = $this->db->execute($updateSql, [$payload, $expiresAt, $id])->getRowCount();

        if ($updated === 0 || $updated === null) {
            try {
                $this->db->execute(
                    'INSERT INTO ' . self::TABLE . ' (id, payload, expires_at) VALUES (?, ?, ?)',
                    [$id, $payload, $expiresAt],
                );
            } catch (QueryException) {
                $this->db->execute($updateSql, [$payload, $expiresAt, $id]);
            }
        }
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

    private static function now(): string
    {
        return self::formatTimestamp(\time());
    }

    private static function formatTimestamp(int $unix): string
    {
        return \gmdate('Y-m-d H:i:s', $unix);
    }
}
