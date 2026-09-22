<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use InvalidArgumentException;
use Kinetis\Search\WriteCondition;
use PHPUnit\Framework\TestCase;

/**
 * The parameters both engines take to apply a write conditionally, and
 * the domains they share. The map is the whole value: it travels onto a
 * direct call's query string and into a bulk action line unchanged.
 */
final class WriteConditionTest extends TestCase
{
    public function test_an_external_version_is_the_version_and_its_type(): void
    {
        self::assertSame(
            ['version' => 7, 'version_type' => 'external'],
            WriteCondition::external(7)->parameters,
        );
    }

    /**
     * The one difference from `external`, and the whole reason the two
     * exist separately: `external_gte` admits a write at the version
     * already stored.
     */
    public function test_an_external_or_equal_version_asks_for_the_gte_type(): void
    {
        self::assertSame(
            ['version' => 7, 'version_type' => 'external_gte'],
            WriteCondition::externalOrEqual(7)->parameters,
        );
    }

    public function test_an_unchanged_document_is_named_by_sequence_number_and_primary_term(): void
    {
        self::assertSame(
            ['if_seq_no' => 4, 'if_primary_term' => 1],
            WriteCondition::ifUnchanged(4, 1)->parameters,
        );
    }

    /**
     * Zero is a version both engines store, so the guard has to refuse
     * below it rather than at it.
     */
    public function test_version_zero_and_sequence_number_zero_are_admitted(): void
    {
        self::assertSame(0, WriteCondition::external(0)->parameters['version']);
        self::assertSame(0, WriteCondition::externalOrEqual(0)->parameters['version']);
        self::assertSame(0, WriteCondition::ifUnchanged(0, 1)->parameters['if_seq_no']);
    }

    public function test_a_negative_external_version_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An external version is 0 or greater; -1 given.');

        WriteCondition::external(-1);
    }

    public function test_a_negative_external_or_equal_version_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An external version is 0 or greater; -1 given.');

        WriteCondition::externalOrEqual(-1);
    }

    public function test_a_negative_sequence_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A sequence number is 0 or greater; -1 given.');

        WriteCondition::ifUnchanged(-1, 1);
    }

    /**
     * A primary term starts at one, which is the domain that differs
     * from the other two.
     */
    public function test_a_primary_term_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A primary term is 1 or greater; 0 given.');

        WriteCondition::ifUnchanged(4, 0);
    }
}
