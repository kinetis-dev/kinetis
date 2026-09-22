<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use InvalidArgumentException;
use Kinetis\Search\BulkOperation;
use Kinetis\Search\WriteCondition;
use PHPUnit\Framework\TestCase;

/**
 * The lines both engines' bulk endpoints read: an action line, and a
 * document line for everything but a delete.
 */
final class BulkOperationTest extends TestCase
{
    public function test_an_index_operation_names_its_target_and_carries_the_document(): void
    {
        self::assertSame(
            [['index' => ['_index' => 'articles', '_id' => '1']], ['title' => 'Kinetis']],
            BulkOperation::index('articles', '1', ['title' => 'Kinetis'])->lines(),
        );
    }

    public function test_an_index_operation_without_an_id_lets_the_cluster_assign_one(): void
    {
        self::assertSame(
            [['index' => ['_index' => 'articles']], ['title' => 'Kinetis']],
            BulkOperation::index('articles', null, ['title' => 'Kinetis'])->lines(),
        );
    }

    public function test_a_create_operation_uses_the_create_action(): void
    {
        self::assertSame(
            [['create' => ['_index' => 'articles', '_id' => '1']], ['title' => 'Kinetis']],
            BulkOperation::create('articles', '1', ['title' => 'Kinetis'])->lines(),
        );
    }

    /**
     * An update's document line is the partial document under `doc`,
     * which is what makes it a merge rather than a replacement.
     */
    public function test_an_update_operation_wraps_its_partial_document(): void
    {
        self::assertSame(
            [['update' => ['_index' => 'articles', '_id' => '1']], ['doc' => ['title' => 'Renamed']]],
            BulkOperation::update('articles', '1', ['title' => 'Renamed'])->lines(),
        );
    }

    public function test_a_delete_operation_is_one_line(): void
    {
        self::assertSame(
            [['delete' => ['_index' => 'articles', '_id' => '1']]],
            BulkOperation::delete('articles', '1')->lines(),
        );
    }

    /**
     * A condition's parameters go in the action line's own metadata,
     * beside the target, rather than anywhere near the document line.
     */
    public function test_a_condition_joins_the_action_metadata_of_an_index(): void
    {
        self::assertSame(
            [
                ['index' => ['_index' => 'articles', '_id' => '1', 'version' => 7, 'version_type' => 'external']],
                ['title' => 'Kinetis'],
            ],
            BulkOperation::index('articles', '1', ['title' => 'Kinetis'], WriteCondition::external(7))->lines(),
        );
    }

    public function test_a_condition_joins_the_action_metadata_of_a_delete(): void
    {
        self::assertSame(
            [['delete' => ['_index' => 'articles', '_id' => '1', 'if_seq_no' => 4, 'if_primary_term' => 2]]],
            BulkOperation::delete('articles', '1', WriteCondition::ifUnchanged(4, 2))->lines(),
        );
    }

    /**
     * The same rule a direct conditional index follows: a condition
     * names one document, and a cluster-assigned id is not one.
     */
    public function test_a_conditional_index_without_an_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A conditional index names the document it writes; $id is null.');

        BulkOperation::index('articles', null, ['title' => 'Kinetis'], WriteCondition::externalOrEqual(7));
    }
}
