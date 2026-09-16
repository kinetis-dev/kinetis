<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Fiber;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Branch;
use Kinetis\Orm\Tests\Fixtures\Cell;
use Kinetis\Orm\Tests\Fixtures\Crate;
use Kinetis\Orm\Tests\Fixtures\Item;
use Kinetis\Orm\Tests\Fixtures\Knot;
use Kinetis\Orm\Tests\Fixtures\Part;
use Kinetis\Orm\Tests\Fixtures\Seal;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Aggregate graph persistence against a scripted transaction: the entities
 * one flush discovers across owned relationships, the order its statements
 * take, the keys it carries between them, the deletions and fix-ups an
 * owned relationship calls for, and every refusal before SQL.
 */
final class AggregateTest extends TestCase
{
    private const string CRATES = 'SELECT `id`, `code` FROM `crates`';

    private const string ITEMS = 'SELECT `id`, `crate_id`, `name` FROM `items`';

    private const string SEALS = 'SELECT `id`, `crate_id`, `stamp` FROM `seals`';

    private const string CELLS = 'SELECT `id`, `twin_id`, `name` FROM `cells`';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create($this->link, MetadataRegistry::fromClasses([
            Branch::class,
            Cell::class,
            Crate::class,
            Item::class,
            Knot::class,
            Part::class,
            Seal::class,
        ]));
        $this->entities = $this->factory->open();
    }

    public function test_persisting_a_root_inserts_its_whole_owned_graph_in_dependency_order(): void
    {
        $crate = Crate::of('C1');
        $first = Item::in($crate, 'first');
        $second = Item::in($crate, 'second');
        $bolt = Part::of($first, 9, 'bolt');
        $crate->items = [$first, $second];
        $crate->seal = Seal::on($crate, 'wax');
        $first->parts = [$bolt];
        $second->parts = [];

        $this->entities->persist($crate);
        $this->transaction->queue(self::insertId(1), self::insertId(5), self::insertId(6), self::affected(1), self::insertId(3));
        $keysAtCommit = null;
        $this->transaction->onCommit = static function () use ($crate, $first, &$keysAtCommit): void {
            $keysAtCommit = [$crate->id, $first->id];
        };

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `crates` (`code`) VALUES (?)', 'params' => ['C1']],
            ['sql' => 'INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)', 'params' => [1, 'first']],
            ['sql' => 'INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)', 'params' => [1, 'second']],
            ['sql' => 'INSERT INTO `parts` (`id`, `item_id`, `name`) VALUES (?, ?, ?)', 'params' => [9, 5, 'bolt']],
            ['sql' => 'INSERT INTO `seals` (`crate_id`, `stamp`) VALUES (?, ?)', 'params' => [1, 'wax']],
        ], $this->transaction->calls);
        self::assertSame([null, null], $keysAtCommit, 'a generated key reaches the next statement, not the object');
        self::assertSame([1, 5, 6, 3], [$crate->id, $first->id, $second->id, $crate->seal?->id]);
        self::assertSame($crate, $this->entities->repository(Crate::class)->find(1));
        self::assertSame($bolt, $this->entities->repository(Part::class)->find(9));

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'every inserted entity is clean');
    }

    public function test_separately_persisted_entities_are_ordered_by_the_graph_not_by_persist_order(): void
    {
        $crate = Crate::of('C1');
        $item = Item::in($crate, 'first');

        // Neither entity names the other through an owned relationship, and
        // the child is persisted first.
        $this->entities->persist($item);
        $this->entities->persist($crate);
        $this->transaction->queue(self::insertId(1), self::insertId(5));

        $this->entities->flush();

        self::assertSame([
            'INSERT INTO `crates` (`code`) VALUES (?)',
            'INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)',
        ], $this->transaction->statements());
        self::assertSame([1, 'first'], $this->transaction->calls[1]['params']);
    }

    public function test_a_managed_row_is_reassigned_to_a_target_the_same_flush_inserts(): void
    {
        $this->link->queue([self::itemRow(5, 1, 'first')]);
        $item = $this->entities->repository(Item::class)->findOrFail(5);
        $crate = Crate::of('C2');
        $item->crate = $crate;
        $this->entities->persist($crate);
        $this->transaction->queue(self::insertId(2), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `crates` (`code`) VALUES (?)', 'params' => ['C2']],
            ['sql' => 'UPDATE `items` SET `crate_id` = 2 WHERE `id` = 5', 'params' => []],
        ], $this->transaction->calls);
        self::assertSame(2, $crate->id);
    }

    public function test_a_nullable_loop_inserts_null_and_fills_the_key_in_afterwards(): void
    {
        $cell = Cell::named('a');
        $cell->twin = $cell;
        $this->entities->persist($cell);
        $this->transaction->queue(self::insertId(7), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `cells` (`twin_id`, `name`) VALUES (?, ?)', 'params' => [null, 'a']],
            ['sql' => 'UPDATE `cells` SET `twin_id` = 7 WHERE `id` = 7', 'params' => []],
        ], $this->transaction->calls);
        self::assertSame(7, $cell->id);

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the committed snapshot holds the key the fix-up wrote');
    }

    public function test_a_two_row_nullable_loop_inserts_both_rows_before_the_fix_up(): void
    {
        $first = Cell::named('a');
        $second = Cell::named('b');
        $first->twin = $second;
        $second->twin = $first;
        $this->entities->persist($first);
        $this->entities->persist($second);
        $this->transaction->queue(self::insertId(7), self::insertId(8), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `cells` (`twin_id`, `name`) VALUES (?, ?)', 'params' => [null, 'a']],
            ['sql' => 'INSERT INTO `cells` (`twin_id`, `name`) VALUES (?, ?)', 'params' => [7, 'b']],
            ['sql' => 'UPDATE `cells` SET `twin_id` = 8 WHERE `id` = 7', 'params' => []],
        ], $this->transaction->calls);
        self::assertSame([7, 8], [$first->id, $second->id]);
    }

    public function test_a_fix_up_that_matches_no_row_fails_the_flush_before_commit(): void
    {
        $cell = Cell::named('a');
        $cell->twin = $cell;
        $this->entities->persist($cell);
        $this->transaction->queue(self::insertId(7), self::affected(0));

        try {
            $this->entities->flush();
            self::fail('The fix-up was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(InvalidEntityStateException::missingRow(Cell::class, 'UPDATE')->getMessage(), $e->getMessage());
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertNull($cell->id, 'no key was applied');
        self::assertTrue($this->entities->contains($cell));
    }

    public function test_a_loop_of_not_null_foreign_keys_sends_no_sql(): void
    {
        $first = Knot::numbered(1);
        $second = Knot::numbered(2);
        $first->partner = $second;
        $second->partner = $first;
        $this->entities->persist($first);
        $this->entities->persist($second);

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('These relationships reference each other in a loop');

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_loop_refusal_names_the_relationships_and_no_identifier(): void
    {
        $knot = Knot::numbered(1);
        $knot->partner = $knot;
        $this->entities->persist($knot);

        try {
            $this->entities->flush();
            self::fail('The loop was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(
                InvalidEntityStateException::unbreakableCycle([Knot::class . '::$partner'])->getMessage(),
                $e->getMessage(),
            );
        }
    }

    public function test_discovery_refuses_an_invalid_late_node_and_schedules_nothing(): void
    {
        $crate = Crate::of('C1');
        $first = Item::in($crate, 'first');
        $second = new Item();
        $second->crate = $crate;
        $crate->items = [$first, $second];
        $this->entities->persist($crate);

        try {
            $this->entities->flush();
            self::fail('The incomplete child was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(InvalidEntityStateException::uninitialized(Item::class, 'name')->getMessage(), $e->getMessage());
        }

        self::assertSame(0, $this->link->begins);
        self::assertFalse($this->entities->contains($first), 'the child reached before the invalid one was not scheduled');
        self::assertTrue($this->entities->contains($crate));
    }

    public function test_discovery_refuses_an_identity_a_held_object_already_takes(): void
    {
        $this->link->queue([self::itemRow(5, 1, 'first')], [self::partRow(9, 5, 'bolt')]);
        $item = $this->entities->repository(Item::class)->query()->with('parts')->first();
        self::assertInstanceOf(Item::class, $item);
        $item->parts = [...$item->parts, Part::of($item, 9, 'second bolt')];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(InvalidEntityStateException::identityConflict(Part::class)->getMessage());

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_child_attached_after_its_root_was_persisted_is_discovered_by_the_flush(): void
    {
        $crate = Crate::of('C1');
        $crate->items = [];
        $crate->seal = null;
        $this->entities->persist($crate);
        $crate->items = [Item::in($crate, 'late')];
        $this->transaction->queue(self::insertId(1), self::insertId(5));

        $this->entities->flush();

        self::assertSame([
            'INSERT INTO `crates` (`code`) VALUES (?)',
            'INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)',
        ], $this->transaction->statements());
    }

    public function test_a_child_dropped_from_a_loaded_collection_is_deleted_with_its_own_aggregate(): void
    {
        $crate = $this->loadCrate([self::partRow(9, 6, 'bolt')]);
        [$first, $second] = $crate->items;
        $crate->items = [$first];
        $this->transaction->queue(self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `parts` WHERE `id` = 9',
            'DELETE FROM `items` WHERE `id` = 6',
        ], $this->transaction->statements(), 'the row whose foreign key names the other is deleted first');
        self::assertFalse($this->entities->contains($second));
        self::assertNull($this->entities->repository(Item::class)->find(6));
    }

    public function test_an_orphan_whose_own_owned_relationship_was_never_loaded_is_refused(): void
    {
        $crate = $this->loadCrateItems();
        [$first] = $crate->items;
        $crate->items = [$first];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(InvalidEntityStateException::ownedRelationNotLoaded(Item::class, 'parts')->getMessage());

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_child_moved_to_another_owner_is_updated_and_not_deleted(): void
    {
        [$first, $second] = $this->loadCrates();
        $moved = $first->items[0];
        $moved->crate = $second;
        $first->items = [];
        $second->items = [$second->items[0], $moved];
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();

        self::assertSame(['UPDATE `items` SET `crate_id` = 2 WHERE `id` = 5'], $this->transaction->statements());
        self::assertTrue($this->entities->contains($moved));
    }

    public function test_a_child_moved_out_of_a_loaded_collection_into_a_loaded_one_that_does_not_hold_it_sends_no_sql(): void
    {
        [$first, $second] = $this->loadCrates();
        $moved = $first->items[0];
        $moved->crate = $second;
        $first->items = [];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(InvalidEntityStateException::reparentedChildMissing(Crate::class, 'items', Item::class)->getMessage());

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_child_whose_foreign_key_names_another_owner_sends_no_sql(): void
    {
        [$first, $second] = $this->loadCrates();
        $first->items = [...$first->items, $second->items[0]];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(InvalidEntityStateException::ownedChildElsewhere(Crate::class, 'items', Item::class)->getMessage());

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_one_row_in_two_loaded_collections_and_one_row_twice_in_one_are_refused(): void
    {
        [$first, $second] = $this->loadCrates();
        $shared = $second->items[0];
        $shared->crate = $first;
        $first->items = [...$first->items, $shared];

        try {
            $this->entities->flush();
            self::fail('The shared row was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(
                InvalidEntityStateException::ownedChildShared(Crate::class, 'items', Item::class)->getMessage(),
                $e->getMessage(),
            );
        }

        $second->items = [];
        $first->items = [$first->items[0], $shared, $shared];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(InvalidEntityStateException::duplicateOwnedChild(Crate::class, 'items', Item::class)->getMessage());

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_replacing_an_owned_has_one_deletes_the_old_row_before_inserting_its_replacement(): void
    {
        $this->link->queue([self::crateRow(1, 'C1')], [self::sealRow(3, 1, 'wax')]);
        $crate = $this->entities->repository(Crate::class)->query()->with('seal')->first();
        self::assertInstanceOf(Crate::class, $crate);
        $crate->seal = Seal::on($crate, 'lead');
        $this->transaction->queue(self::affected(1), self::insertId(4));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'DELETE FROM `seals` WHERE `id` = 3', 'params' => []],
            ['sql' => 'INSERT INTO `seals` (`crate_id`, `stamp`) VALUES (?, ?)', 'params' => [1, 'lead']],
        ], $this->transaction->calls, 'the unique foreign-key slot is free before the replacement takes it');
        self::assertSame(4, $crate->seal?->id);
    }

    public function test_removing_an_owner_whose_owned_relationship_was_never_loaded_sends_no_sql(): void
    {
        $this->link->queue([self::crateRow(1, 'C1')]);
        $crate = $this->entities->repository(Crate::class)->findOrFail(1);

        try {
            $this->entities->remove($crate);
            self::fail('The removal was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(
                InvalidEntityStateException::ownedRelationNotLoaded(Crate::class, 'items')->getMessage(),
                $e->getMessage(),
            );
        }

        $this->entities->flush();
        self::assertSame(0, $this->link->begins);
        self::assertTrue($this->entities->contains($crate));
    }

    public function test_removing_a_loaded_owner_deletes_its_children_before_it(): void
    {
        $crate = $this->loadCrate();
        $this->entities->remove($crate);
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `items` WHERE `id` = 5',
            'DELETE FROM `items` WHERE `id` = 6',
            'DELETE FROM `crates` WHERE `id` = 1',
        ], $this->transaction->statements());
        self::assertFalse($this->entities->contains($crate));
    }

    public function test_removing_a_pending_owner_cancels_its_pending_children(): void
    {
        $crate = Crate::of('C1');
        $item = Item::in($crate, 'first');
        $crate->items = [$item];
        $crate->seal = null;
        $item->parts = [Part::of($item, 9, 'bolt')];
        $this->entities->persist($crate);
        $this->entities->persist($item);

        $this->entities->remove($crate);

        self::assertFalse($this->entities->contains($crate));
        self::assertFalse($this->entities->contains($item));
        $this->entities->flush();
        self::assertSame(0, $this->link->begins);
    }

    public function test_persisting_a_removed_owner_restores_its_loaded_owned_subgraph(): void
    {
        $crate = $this->loadCrate();
        $this->entities->remove($crate);

        $this->entities->persist($crate);

        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'the whole aggregate is scheduled again for nothing');
        self::assertTrue($this->entities->contains($crate->items[0]));
    }

    public function test_a_refused_un_remove_cancels_no_deletion_and_a_corrected_retry_deletes_the_aggregate(): void
    {
        $crate = $this->loadCrate([self::partRow(9, 5, 'bolt')]);
        $this->entities->remove($crate);
        $crate->items[1]->parts = [Seal::on($crate, 'S1')];

        try {
            $this->entities->persist($crate);
            self::fail('The un-remove was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(
                InvalidEntityStateException::relationTargetNotHeld(Item::class, 'parts')->getMessage(),
                $e->getMessage(),
            );
        }

        $crate->items[1]->parts = [];
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `items` WHERE `id` = 6',
            'DELETE FROM `parts` WHERE `id` = 9',
            'DELETE FROM `items` WHERE `id` = 5',
            'DELETE FROM `crates` WHERE `id` = 1',
        ], $this->transaction->statements(), 'the refused un-remove cancelled no part of the scheduled deletion');
    }

    public function test_a_committed_deleted_child_left_in_a_collection_is_refused_rather_than_reinserted(): void
    {
        $crate = $this->loadCrate();
        [$first, $second] = $crate->items;
        $crate->items = [$first];
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        $crate->items = [$first, $second];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::deletedChildRediscovered(Crate::class, 'items', Item::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(1, $this->link->begins, 'no second transaction began');
        }
    }

    public function test_a_refused_re_persist_leaves_the_deleted_object_refused_by_discovery(): void
    {
        $crate = $this->loadCrate();
        [$first, $second] = $crate->items;
        $crate->items = [$first];
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        $crate->items = [$first, $second];
        // The load never wrote the inverse side, and extract() reads every
        // mapped property before the identifier is judged.
        $second->crate = $crate;

        try {
            $this->entities->persist($second);
            self::fail('The re-persist was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(
                InvalidEntityStateException::generatedIdentifierSet(Item::class)->getMessage(),
                $e->getMessage(),
                'the detached object still carries the identifier of the row that was deleted',
            );
        }

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::deletedChildRediscovered(Crate::class, 'items', Item::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(1, $this->link->begins, 'no second transaction began');
        }
    }

    public function test_persist_inserts_a_detached_object_the_manager_deleted(): void
    {
        $crate = $this->loadCrate();
        [$first, $second] = $crate->items;
        $crate->items = [$first];
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        $second->id = null;
        $second->crate = $crate;
        $crate->items = [$first, $second];
        $this->entities->persist($second);
        $this->link->transaction = $second_transaction = new SpyMysqlTransaction();
        $second_transaction->queue(self::insertId(7));

        $this->entities->flush();

        self::assertSame(['INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)'], $second_transaction->statements());
    }

    public function test_a_nullable_delete_loop_is_nullified_before_the_rows_are_deleted(): void
    {
        $this->link->queue([self::cellRow(7, 8, 'a'), self::cellRow(8, 7, 'b')]);
        [$first, $second] = $this->entities->repository(Cell::class)->query()->get();
        $this->entities->remove($first);
        $this->entities->remove($second);
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'UPDATE `cells` SET `twin_id` = ? WHERE `id` = ?',
            'DELETE FROM `cells` WHERE `id` = 7',
            'DELETE FROM `cells` WHERE `id` = 8',
        ], $this->transaction->statements(), 'one key is nulled so the rows can be deleted in order');
        self::assertSame([null, 8], $this->transaction->calls[0]['params']);
    }

    public function test_a_delete_loop_of_not_null_foreign_keys_sends_no_sql(): void
    {
        $this->link->queue([['id' => 1, 'partner_id' => 2], ['id' => 2, 'partner_id' => 1]]);
        [$first, $second] = $this->entities->repository(Knot::class)->query()->get();
        $this->entities->remove($first);
        $this->entities->remove($second);

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('These relationships reference each other in a loop');

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_reparenting_update_precedes_the_old_owners_delete(): void
    {
        [$first, $second] = $this->loadCrates();
        $moved = $first->items[0];
        $moved->crate = $second;
        $first->items = [];
        $second->items = [$second->items[0], $moved];
        $this->entities->remove($first);
        $this->transaction->queue(self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'UPDATE `items` SET `crate_id` = 2 WHERE `id` = 5',
            'DELETE FROM `crates` WHERE `id` = 1',
        ], $this->transaction->statements());
    }

    public function test_a_foreign_key_naming_a_row_the_flush_deletes_is_refused(): void
    {
        [$first, $second] = $this->loadCrates();
        $this->entities->remove($first);
        $first->items = [...$first->items, Item::in($first, 'late')];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::referencesRemovedRow(Item::class, 'crate', Crate::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_an_aggregate_that_owns_its_own_class_is_inserted_from_the_root_down(): void
    {
        $root = Branch::under(null, 'root');
        $child = Branch::under($root, 'child');
        $leaf = Branch::under($child, 'leaf');
        $root->children = [$child];
        $child->children = [$leaf];
        $leaf->children = [];
        $this->entities->persist($root);
        $this->transaction->queue(self::insertId(1), self::insertId(2), self::insertId(3));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `branches` (`parent_id`, `name`) VALUES (?, ?)', 'params' => [null, 'root']],
            ['sql' => 'INSERT INTO `branches` (`parent_id`, `name`) VALUES (?, ?)', 'params' => [1, 'child']],
            ['sql' => 'INSERT INTO `branches` (`parent_id`, `name`) VALUES (?, ?)', 'params' => [2, 'leaf']],
        ], $this->transaction->calls);
        self::assertSame([1, 2, 3], [$root->id, $child->id, $leaf->id]);
    }

    public function test_an_entity_still_naming_an_insert_an_orphan_cancelled_is_refused(): void
    {
        $this->link->queue(
            [self::branchRow(1, null, 'root')],
            [self::branchRow(2, 1, 'child')],
            [],
        );
        $root = $this->entities->repository(Branch::class)->query()->with('children.children')->first();
        self::assertInstanceOf(Branch::class, $root);
        [$child] = $root->children;
        // A new grandchild below the child, and a branch below it that the
        // graph would not reach on its own.
        $grandchild = Branch::under($child, 'grandchild');
        $child->children = [$grandchild];
        $this->entities->persist(Branch::under($grandchild, 'orphaned reference'));
        // Dropping the child deletes it and cancels the grandchild's insert.
        $root->children = [];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::referencesRemovedRow(Branch::class, 'parent', Branch::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_membership_change_alone_writes_nothing(): void
    {
        $crate = $this->loadCrateItems();
        $crate->items = array_reverse($crate->items);

        $this->entities->flush();

        self::assertSame(0, $this->link->begins, 'an owner column is never written from its inverse side');
    }

    public function test_an_unloaded_owned_collection_discovers_new_children_and_removes_nothing(): void
    {
        $this->link->queue([self::crateRow(1, 'C1')]);
        $crate = $this->entities->repository(Crate::class)->findOrFail(1);
        $crate->items = [Item::in($crate, 'added')];
        $this->transaction->queue(self::insertId(5));

        $this->entities->flush();

        self::assertSame(['INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)'], $this->transaction->statements());
        self::assertSame([1, 'added'], $this->transaction->calls[0]['params']);
    }

    public function test_a_statement_failure_and_a_successful_rollback_leave_the_whole_graph_pending(): void
    {
        $crate = Crate::of('C1');
        $crate->items = [Item::in($crate, 'first')];
        $crate->seal = null;
        $this->entities->persist($crate);
        $this->transaction->queue(self::insertId(1));
        $this->transaction->onStatement = function (): void {
            if (count($this->transaction->calls) === 2) {
                throw new QueryException('the item insert failed');
            }
        };

        try {
            $this->entities->flush();
            self::fail('The failure was swallowed.');
        } catch (QueryException $e) {
            self::assertSame('the item insert failed', $e->getMessage());
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertFalse($this->entities->isClosed());
        self::assertNull($crate->id, 'no generated key was applied');
        self::assertTrue($this->entities->contains($crate));
        self::assertFalse($this->entities->contains($crate->items[0]), 'the discovered child is scheduled again by the next flush');

        $this->transaction->onStatement = null;
        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::insertId(1), self::insertId(5));

        $this->entities->flush();

        self::assertSame([
            'INSERT INTO `crates` (`code`) VALUES (?)',
            'INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)',
        ], $retry->statements(), 'the retry discovered and sent the whole graph again');
    }

    public function test_a_rollback_failure_closes_the_manager_with_the_whole_graph_unapplied(): void
    {
        $crate = $this->pendingCrate();
        $this->transaction->queue(self::insertId(1));
        $this->transaction->onStatement = function (): void {
            if (count($this->transaction->calls) === 2) {
                throw new QueryException('the item insert failed');
            }
        };
        $this->transaction->onRollback = static fn () => throw new QueryException('the rollback failed');

        $this->expectException(RollbackFailedException::class);

        try {
            $this->entities->flush();
        } finally {
            self::assertTrue($this->entities->isClosed());
            self::assertNull($crate->id);
        }
    }

    public function test_a_commit_failure_leaves_the_whole_graph_unapplied(): void
    {
        $crate = $this->pendingCrate();
        $this->transaction->queue(self::insertId(1), self::insertId(5));
        $this->transaction->onCommit = static fn () => throw new QueryException('the commit failed');

        $this->expectException(UnknownFlushOutcomeException::class);

        try {
            $this->entities->flush();
        } finally {
            self::assertTrue($this->entities->isClosed());
            self::assertSame([null, null], [$crate->id, $crate->items[0]->id]);
        }
    }

    public function test_a_session_applies_the_whole_graph_only_after_the_outer_commit_returns(): void
    {
        $crate = null;
        $keysAtCommit = null;
        $this->transaction->queue(self::insertId(1), self::insertId(5));
        $this->transaction->onCommit = static function () use (&$crate, &$keysAtCommit): void {
            self::assertInstanceOf(Crate::class, $crate);
            $keysAtCommit = [$crate->id, $crate->items[0]->id];
        };

        $this->factory->transaction(static function (EntityManager $entities) use (&$crate): void {
            $crate = Crate::of('C1');
            $crate->items = [Item::in($crate, 'first')];
            $crate->seal = null;
            $entities->persist($crate);
            $entities->flush();
        });

        self::assertInstanceOf(Crate::class, $crate);
        self::assertSame([null, null], $keysAtCommit);
        self::assertSame([1, 5], [$crate->id, $crate->items[0]->id]);
        self::assertSame([], $this->link->calls);
    }

    public function test_a_collection_changed_while_the_flush_waits_stays_a_difference_for_the_next_flush(): void
    {
        $crate = $this->loadCrate();
        [$first, $second] = $crate->items;
        $added = Item::in($crate, 'added');
        $added->parts = [];
        $crate->items = [$first, $second, $added];
        $this->transaction->queue(self::insertId(7));
        $this->transaction->onStatement = static function () use ($crate, $first): void {
            $crate->items = [$first];
        };

        $this->entities->flush();

        self::assertSame(['INSERT INTO `items` (`crate_id`, `name`) VALUES (?, ?)'], $this->transaction->statements());
        $this->transaction->onStatement = null;
        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `items` WHERE `id` = 6',
            'DELETE FROM `items` WHERE `id` = 7',
        ], $next->statements(), 'the membership the flush sent is the baseline the later change is measured against');
    }

    public function test_a_close_from_another_fiber_stops_the_plan_before_its_fix_up(): void
    {
        $cell = Cell::named('a');
        $cell->twin = $cell;
        $this->entities->persist($cell);
        $this->transaction->queue(self::insertId(7), self::affected(1));
        $manager = $this->entities;
        $this->transaction->onStatement = static function () use ($manager): void {
            $closer = new Fiber(static fn () => $manager->close());
            $closer->start();
        };

        self::assertThrows(ClosedEntityManagerException::class, fn () => $this->entities->flush());

        self::assertSame(['INSERT INTO `cells` (`twin_id`, `name`) VALUES (?, ?)'], $this->transaction->statements());
        self::assertSame(['close', 'rollback'], $this->transaction->ends, 'the rollback reaches a transaction close() already ended');
        self::assertNull($cell->id);
    }

    /**
     * One crate with two items, its parts and its seal: the whole aggregate
     * loaded, which is what an aggregate removal or a membership diff needs.
     *
     * @param list<array<string, int|string>> $parts
     */
    private function loadCrate(array $parts = []): Crate
    {
        $this->link->queue(
            [self::crateRow(1, 'C1')],
            [self::itemRow(5, 1, 'first'), self::itemRow(6, 1, 'second')],
            $parts,
            [],
        );
        $crate = $this->entities->repository(Crate::class)->query()->with('items.parts', 'seal')->first();
        self::assertInstanceOf(Crate::class, $crate);

        return $crate;
    }

    /** One crate whose items are loaded and whose second level is not. */
    private function loadCrateItems(): Crate
    {
        $this->link->queue([self::crateRow(1, 'C1')], [self::itemRow(5, 1, 'first'), self::itemRow(6, 1, 'second')]);
        $crate = $this->entities->repository(Crate::class)->query()->with('items')->first();
        self::assertInstanceOf(Crate::class, $crate);

        return $crate;
    }

    /**
     * Two crates, each with one item, and the whole aggregate below both.
     *
     * @return array{Crate, Crate}
     */
    private function loadCrates(): array
    {
        $this->link->queue(
            [self::crateRow(1, 'C1'), self::crateRow(2, 'C2')],
            [self::itemRow(5, 1, 'first'), self::itemRow(6, 2, 'second')],
            [],
            [],
        );
        [$first, $second] = $this->entities->repository(Crate::class)->query()->with('items.parts', 'seal')->get();

        return [$first, $second];
    }

    private function pendingCrate(): Crate
    {
        $crate = Crate::of('C1');
        $crate->items = [Item::in($crate, 'first')];
        $crate->seal = null;
        $this->entities->persist($crate);

        return $crate;
    }

    /**
     * @return array<string, int|string>
     */
    private static function crateRow(int $id, string $code): array
    {
        return ['id' => $id, 'code' => $code];
    }

    /**
     * @return array<string, int|string>
     */
    private static function itemRow(int $id, int $crate, string $name): array
    {
        return ['id' => $id, 'crate_id' => $crate, 'name' => $name];
    }

    /**
     * @return array<string, int|string>
     */
    private static function partRow(int $id, int $item, string $name): array
    {
        return ['id' => $id, 'item_id' => $item, 'name' => $name];
    }

    /**
     * @return array<string, int|string>
     */
    private static function sealRow(int $id, int $crate, string $stamp): array
    {
        return ['id' => $id, 'crate_id' => $crate, 'stamp' => $stamp];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function branchRow(int $id, ?int $parent, string $name): array
    {
        return ['id' => $id, 'parent_id' => $parent, 'name' => $name];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function cellRow(int $id, ?int $twin, string $name): array
    {
        return ['id' => $id, 'twin_id' => $twin, 'name' => $name];
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }

    private static function insertId(int $id): SqlResult
    {
        return new BufferedSqlResult([], 1, null, $id);
    }

    /**
     * @param class-string<Throwable> $expected
     * @param Closure(): mixed $call
     */
    private static function assertThrows(string $expected, Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);

            return;
        }

        self::fail("Expected {$expected}.");
    }
}
