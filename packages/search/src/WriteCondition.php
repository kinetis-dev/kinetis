<?php

declare(strict_types=1);

namespace Kinetis\Search;

use InvalidArgumentException;

/**
 * What a document must already be true of for a write to apply: the
 * condition parameters both engines take on a direct index or delete and
 * inside a bulk action line, assembled once here rather than spelled out
 * at each call site.
 *
 * A projection fed by an at-least-once delivery is what needs this. The
 * authority's own revision number travels with the document as an
 * external version, so a delivery carrying an older one is refused by
 * the engine rather than moving the index backwards, and neither a
 * replay nor a reordered delivery can undo a newer write.
 * {@see self::ifUnchanged()} is the other shape: the `_seq_no` and
 * `_primary_term` a {@see SearchClient::get()} answered, which refuses a
 * read-modify-write that something else wrote in between.
 *
 * {@see self::externalOrEqual()} admits a write at the version already
 * stored, which is what makes a duplicate delivery harmless — and what
 * requires the document to be a deterministic function of that version,
 * since an equal version overwrites what is there.
 *
 * A versioned delete fences a stale write only while the engine still
 * holds the deleted document's version, which it discards once
 * `index.gc_deletes` has passed. A projection that must stay fenced
 * beyond that writes a versioned tombstone document rather than deleting
 * one.
 */
final readonly class WriteCondition
{
    /**
     * @param array{version: int, version_type: 'external'|'external_gte'}|array{if_seq_no: int, if_primary_term: int} $parameters
     */
    private function __construct(public array $parameters)
    {
    }

    /**
     * Applies the write only when $version is newer than the version the
     * document holds, and stores $version as the new one.
     *
     * @throws InvalidArgumentException
     */
    public static function external(int $version): self
    {
        return new self([
            'version' => self::atLeast($version, 0, 'An external version'),
            'version_type' => 'external',
        ]);
    }

    /**
     * Applies the write when $version is newer than or equal to the
     * version the document holds.
     *
     * @throws InvalidArgumentException
     */
    public static function externalOrEqual(int $version): self
    {
        return new self([
            'version' => self::atLeast($version, 0, 'An external version'),
            'version_type' => 'external_gte',
        ]);
    }

    /**
     * Applies the write only when the document still sits at exactly the
     * $sequenceNumber and $primaryTerm a {@see SearchClient::get()}
     * envelope reported, so a concurrent write in between refuses it.
     *
     * @throws InvalidArgumentException
     */
    public static function ifUnchanged(int $sequenceNumber, int $primaryTerm): self
    {
        return new self([
            'if_seq_no' => self::atLeast($sequenceNumber, 0, 'A sequence number'),
            'if_primary_term' => self::atLeast($primaryTerm, 1, 'A primary term'),
        ]);
    }

    /**
     * The domains both engines share. A version and a sequence number
     * start at zero; a primary term starts at one.
     */
    private static function atLeast(int $value, int $minimum, string $name): int
    {
        if ($value < $minimum) {
            throw new InvalidArgumentException("{$name} is {$minimum} or greater; {$value} given.");
        }

        return $value;
    }
}
