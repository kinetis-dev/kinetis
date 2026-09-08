<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use Kinetis\Search\BulkOperation;
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
}
