<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Exception\QueryException;
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
 * the exact second a session actually expires. The absolute timestamp
 * itself is computed and validated via {@see SessionExpiry} — never
 * `time() + $lifetimeSeconds` directly — so an invalid or
 * unrepresentable `$lifetimeSeconds` fails with a clear package
 * exception rather than a raw `TypeError` out of formatTimestamp().
 *
 * `expires_at` must be a *timezone-naive* column type — MySQL's
 * `DATETIME`, or Postgres's `TIMESTAMP` (without time zone, the
 * default) — never MySQL's own `TIMESTAMP`, which is deliberately not
 * used by the shipped migration stub; see {@see SessionExpiry}'s own
 * `MAX_EXPIRES_AT` docblock for why. Every value this class binds is a
 * literal `gmdate()`-formatted UTC wall-clock string with no embedded
 * offset, which is what a genuinely timezone-naive column stores
 * exactly as given, making the comparison against `self::now()`'s own
 * identically-formatted string correct regardless of the connection's
 * own session timezone. This class never mutates the connection's own
 * timezone itself: a shared application connection's session settings
 * are not this package's to change, so the fix is choosing a column
 * type the ambient setting cannot affect in the first place.
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
        $expiresAt = self::formatTimestamp(SessionExpiry::timestampFor($lifetimeSeconds));

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
            } catch (QueryException $insertFailure) {
                // The INSERT's own QueryException is not proof of a
                // duplicate-key race specifically — it's equally what a
                // connection failure, a constraint violation, an
                // encoding/payload-size rejection, or a permissions
                // error looks like. Retrying the UPDATE and trusting a
                // nonzero row count is safe (it's a real, driver-reported
                // change), but a retry reporting 0/null proves nothing
                // either way — that's legitimate driver behavior for a
                // byte-identical repeat write, not evidence the row
                // exists at all. Only a direct existence check settles
                // it, and it's pushed into the WHERE clause itself
                // (id/payload/expires_at all bound as params) rather
                // than compared in PHP against whatever format the
                // driver happens to return a TIMESTAMP column as — the
                // database's own typed comparison is what actually
                // proves the intended values landed, not a string match
                // against unknown driver-specific formatting.
                $retried = $this->db->execute($updateSql, [$payload, $expiresAt, $id])->getRowCount();

                if ($retried === 0 || $retried === null) {
                    // Deliberately not wrapped in try/catch: a failure
                    // here is a genuinely new, distinct error and must
                    // propagate as itself — never silently swallowed
                    // into a false "recovered" or misattributed to the
                    // original insert failure.
                    $verified = $this->db
                        ->execute(
                            'SELECT id FROM ' . self::TABLE . ' WHERE id = ? AND payload = ? AND expires_at = ?',
                            [$id, $payload, $expiresAt],
                        )
                        ->fetchRow();

                    if ($verified === null) {
                        throw $insertFailure;
                    }
                }
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
