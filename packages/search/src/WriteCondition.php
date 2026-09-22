<?php

declare(strict_types=1);

namespace Kinetis\Search;

use InvalidArgumentException;

/**
 * The precondition a write carries: the condition parameters both
 * engines evaluate for the named document before applying a direct
 * index or delete, or a bulk action line, assembled once here rather
 * than spelled out at each call site. Each named constructor defines
 * exactly what it admits, a document that is not there yet included.
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
     * @param array{version: non-negative-int, version_type: 'external'|'external_gte'}|array{if_seq_no: non-negative-int, if_primary_term: positive-int} $parameters
     */
    private function __construct(public array $parameters)
    {
    }

    /**
     * Applies the write when $version is newer than the version the
     * document holds, and stores $version as the new one. A document
     * that is not there holds no version, so the write creates it.
     *
     * @throws InvalidArgumentException
     */
    public static function external(int $version): self
    {
        return new self([
            'version' => self::nonNegative($version, 'An external version'),
            'version_type' => 'external',
        ]);
    }

    /**
     * Applies the write when $version is newer than or equal to the
     * version the document holds, and creates one that is not there the
     * same way {@see self::external()} does.
     *
     * @throws InvalidArgumentException
     */
    public static function externalOrEqual(int $version): self
    {
        return new self([
            'version' => self::nonNegative($version, 'An external version'),
            'version_type' => 'external_gte',
        ]);
    }

    /**
     * Applies the write only while the document still sits at exactly
     * the $sequenceNumber and $primaryTerm a {@see SearchClient::get()}
     * envelope reported. Nothing else satisfies it: a document something
     * wrote in between, and a document that is not there at all, are
     * both conflicts.
     *
     * @throws InvalidArgumentException
     */
    public static function ifUnchanged(int $sequenceNumber, int $primaryTerm): self
    {
        return new self([
            'if_seq_no' => self::nonNegative($sequenceNumber, 'A sequence number'),
            'if_primary_term' => self::positive($primaryTerm, 'A primary term'),
        ]);
    }

    /**
     * A version and a sequence number start at zero on both engines.
     *
     * @return non-negative-int
     */
    private static function nonNegative(int $value, string $name): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$name} is 0 or greater; {$value} given.");
        }

        return $value;
    }

    /**
     * A primary term starts at one on both engines.
     *
     * @return positive-int
     */
    private static function positive(int $value, string $name): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$name} is 1 or greater; {$value} given.");
        }

        return $value;
    }
}
