<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Parcel;
use Kinetis\Orm\Tests\Fixtures\Peer;
use Kinetis\Orm\Tests\Fixtures\Ribbon;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\Stamp;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * #[ManyToMany] against a scripted client and transaction: the selects each
 * side of a join table sends, the objects a load assigns, the join rows an
 * owning collection writes and the order it writes them in, what an inverse
 * collection and a reordered array leave alone, and every refusal before
 * SQL. A parcel's and a ribbon's identifiers are ints, which the query
 * builder writes into the SQL; a stamp's is a string, bound as a parameter.
 */
final class JoinRelationshipTest extends TestCase
{
    private const string PARCELS = 'SELECT `id`, `code` FROM `parcels`';

    private const string RIBBONS = 'SELECT `id`, `name` FROM `ribbons`';

    private const string STAMPS = 'SELECT `code`, `issuer` FROM `stamps`';

    private const string PARCEL_RIBBON = 'SELECT `parcel_id`, `ribbon_id` FROM `parcel_ribbon`';

    private const string RIBBON_PARCEL = 'SELECT `ribbon_id`, `parcel_id` FROM `parcel_ribbon`';

    private const string BY_PAIR = ' ORDER BY `parcel_id` ASC, `ribbon_id` ASC';

    private const string BY_OWNER = ' ORDER BY `ribbon_id` ASC, `parcel_id` ASC';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create($this->link, MetadataRegistry::fromClasses([
            Parcel::class,
            Peer::class,
            Ribbon::class,
            Stamp::class,
        ]));
        $this->entities = $this->factory->open();
    }

    public function test_a_new_owner_and_its_new_targets_insert_both_rows_before_each_link(): void
    {
        $parcel = Parcel::of('P1');
        $red = Ribbon::named('red');
        $blue = Ribbon::named('blue');
        $parcel->ribbons = [$red, $blue];
        $parcel->stamps = [];
        $this->entities->persist($parcel);
        $this->entities->persist($red);
        $this->entities->persist($blue);
        $this->transaction->queue(self::insertId(1), self::insertId(9), self::insertId(8), self::affected(1), self::affected(1));
        $keysAtCommit = null;
        $this->transaction->onCommit = static function () use ($parcel, $red, &$keysAtCommit): void {
            $keysAtCommit = [$parcel->id, $red->id];
        };

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `parcels` (`code`) VALUES (?)', 'params' => ['P1']],
            ['sql' => 'INSERT INTO `ribbons` (`name`) VALUES (?)', 'params' => ['red']],
            ['sql' => 'INSERT INTO `ribbons` (`name`) VALUES (?)', 'params' => ['blue']],
            ['sql' => 'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 9)', 'params' => []],
            ['sql' => 'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 8)', 'params' => []],
        ], $this->transaction->calls, 'both endpoints exist before their link, which takes the key their inserts reported');
        self::assertSame([null, null], $keysAtCommit, 'a generated key reaches the link statement, not the object');
        self::assertSame([1, 9, 8], [$parcel->id, $red->id, $blue->id]);

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the committed membership is the baseline the next flush diffs against');
    }

    public function test_the_links_of_one_join_table_are_ordered_by_their_pair_not_by_the_array(): void
    {
        $this->link->queue([self::ribbonRow(6, 'blue')], [self::ribbonRow(5, 'red')]);
        $blue = $this->entities->repository(Ribbon::class)->findOrFail(6);
        $red = $this->entities->repository(Ribbon::class)->findOrFail(5);
        $parcel = Parcel::of('P1');
        $parcel->ribbons = [$blue, $red];
        $parcel->stamps = [];
        $this->entities->persist($parcel);
        $this->transaction->queue(self::insertId(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'INSERT INTO `parcels` (`code`) VALUES (?)',
            'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 5)',
            'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 6)',
        ], $this->transaction->statements(), 'one order per join table, so concurrent flushes take its row locks the same way');
    }

    public function test_a_loaded_collection_diff_deletes_the_removed_pair_and_inserts_the_added_one(): void
    {
        $parcel = $this->loadParcel();
        $green = Ribbon::named('green');
        $this->entities->persist($green);
        $parcel->ribbons = [$parcel->ribbons[1], $green];
        $this->transaction->queue(self::affected(1), self::insertId(7), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'DELETE FROM `parcel_ribbon` WHERE `parcel_id` = 1 AND `ribbon_id` = 5', 'params' => []],
            ['sql' => 'INSERT INTO `ribbons` (`name`) VALUES (?)', 'params' => ['green']],
            ['sql' => 'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 7)', 'params' => []],
        ], $this->transaction->calls, 'the ribbon the array kept is neither deleted nor inserted');
        self::assertSame(7, $green->id);

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the membership the flush sent is the new baseline');
    }

    public function test_reordering_a_loaded_collection_writes_nothing(): void
    {
        $parcel = $this->loadParcel();
        $parcel->ribbons = array_reverse($parcel->ribbons);

        $this->entities->flush();

        self::assertSame(0, $this->link->begins, 'a join collection is a set, and its array order is not its state');
    }

    public function test_one_object_twice_in_a_collection_is_refused_before_sql(): void
    {
        $parcel = $this->loadParcel();
        $parcel->ribbons = [...$parcel->ribbons, $parcel->ribbons[0]];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::duplicateJoinTarget(Parcel::class, 'ribbons', Ribbon::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_managed_collection_the_manager_never_loaded_cannot_write(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')], [self::ribbonRow(5, 'red')]);
        $parcel = $this->entities->repository(Parcel::class)->findOrFail(1);
        $parcel->ribbons = [$this->entities->repository(Ribbon::class)->findOrFail(5)];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::joinCollectionNotLoaded(Parcel::class, 'ribbons')->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_an_uninitialized_collection_on_a_managed_owner_writes_nothing(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')]);
        $parcel = $this->entities->repository(Parcel::class)->findOrFail(1);
        $parcel->code = 'P2';
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();

        self::assertSame(
            ['UPDATE `parcels` SET `code` = ? WHERE `id` = ?'],
            $this->transaction->statements(),
            'a collection no one touched is not a collection emptied',
        );
    }

    public function test_a_changed_inverse_collection_is_inert_and_only_the_owning_side_writes_links(): void
    {
        $this->link->queue(
            [self::ribbonRow(5, 'red')],
            [['ribbon_id' => 5, 'parcel_id' => 1]],
            [self::parcelRow(1, 'P1')],
        );
        $ribbon = $this->entities->repository(Ribbon::class)->query()->with('parcels')->first();
        self::assertInstanceOf(Ribbon::class, $ribbon);
        self::assertSame([
            ['sql' => self::RIBBONS . ' LIMIT 1', 'params' => []],
            ['sql' => self::RIBBON_PARCEL . ' WHERE `ribbon_id` IN (5)' . self::BY_OWNER, 'params' => []],
            ['sql' => self::PARCELS . ' WHERE `id` IN (1)' . ' ORDER BY `id` ASC', 'params' => []],
        ], $this->link->calls, 'the inverse side reads the owning table from its own column');

        $ribbon->parcels = [];

        $this->entities->flush();

        self::assertSame(0, $this->link->begins, 'the inverse side of a join table writes nothing');
    }

    public function test_removing_an_owner_deletes_its_join_rows_before_its_own_row_without_loading_them(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')]);
        $parcel = $this->entities->repository(Parcel::class)->findOrFail(1);
        $this->entities->remove($parcel);
        $this->transaction->queue(self::affected(3), self::affected(0), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `parcel_ribbon` WHERE `parcel_id` = 1',
            'DELETE FROM `parcel_stamp` WHERE `parcel_id` = 1',
            'DELETE FROM `parcels` WHERE `id` = 1',
        ], $this->transaction->statements(), 'one DELETE per join table, by the owner column, whatever it matches');
        self::assertFalse($this->entities->contains($parcel));
    }

    public function test_removing_only_a_target_writes_no_join_row(): void
    {
        $parcel = $this->loadParcel();
        $this->entities->remove($parcel->ribbons[0]);
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();

        self::assertSame(
            ['DELETE FROM `ribbons` WHERE `id` = 5'],
            $this->transaction->statements(),
            "the join table's foreign key, or its ON DELETE CASCADE, decides what a shared target leaves behind",
        );
    }

    public function test_linking_a_target_the_same_flush_deletes_is_refused_before_sql(): void
    {
        $parcel = $this->loadParcel();
        $this->link->queue([self::ribbonRow(8, 'gold')]);
        $gold = $this->entities->repository(Ribbon::class)->findOrFail(8);
        $this->entities->remove($gold);
        $parcel->ribbons = [...$parcel->ribbons, $gold];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::referencesRemovedRow(Parcel::class, 'ribbons', Ribbon::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    public function test_a_duplicate_link_fails_on_the_database_and_leaves_the_whole_flush_pending(): void
    {
        $parcel = $this->loadParcel();
        $green = Ribbon::named('green');
        $this->entities->persist($green);
        $parcel->ribbons = [...$parcel->ribbons, $green];
        $this->transaction->queue(self::insertId(7));
        $this->transaction->onStatement = function (): void {
            if (count($this->transaction->calls) === 2) {
                throw new QueryException('duplicate entry for key parcel_ribbon.PRIMARY');
            }
        };

        try {
            $this->entities->flush();
            self::fail('The duplicate link was accepted.');
        } catch (QueryException $e) {
            self::assertSame('duplicate entry for key parcel_ribbon.PRIMARY', $e->getMessage());
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertNull($green->id, 'no generated key was applied');

        $this->transaction->onStatement = null;
        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::insertId(7), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'INSERT INTO `ribbons` (`name`) VALUES (?)',
            'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 7)',
        ], $retry->statements(), 'no collection baseline was acknowledged, so the retry sends the same link again');
    }

    public function test_deleting_a_link_that_is_already_gone_is_not_a_missing_row(): void
    {
        $parcel = $this->loadParcel();
        $parcel->ribbons = [];
        $this->transaction->queue(self::affected(0), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `parcel_ribbon` WHERE `parcel_id` = 1 AND `ribbon_id` = 5',
            'DELETE FROM `parcel_ribbon` WHERE `parcel_id` = 1 AND `ribbon_id` = 6',
        ], $this->transaction->statements());
        self::assertSame(['commit'], $this->transaction->ends, 'a join row has no version and no row the flush can claim');
    }

    public function test_an_unknown_commit_outcome_applies_no_key_and_no_collection_baseline(): void
    {
        $parcel = Parcel::of('P1');
        $red = Ribbon::named('red');
        $parcel->ribbons = [$red];
        $parcel->stamps = [];
        $this->entities->persist($parcel);
        $this->entities->persist($red);
        $this->transaction->queue(self::insertId(1), self::insertId(9), self::affected(1));
        $this->transaction->onCommit = static fn () => throw new QueryException('the commit failed');

        $this->expectException(UnknownFlushOutcomeException::class);

        try {
            $this->entities->flush();
        } finally {
            self::assertTrue($this->entities->isClosed());
            self::assertSame([null, null], [$parcel->id, $red->id]);
        }
    }

    public function test_a_session_applies_a_join_collection_only_after_the_outer_commit_returns(): void
    {
        $parcel = null;
        $this->transaction->queue(self::insertId(1), self::insertId(9), self::affected(1));

        $this->factory->transaction(static function (EntityManager $entities) use (&$parcel): void {
            $parcel = Parcel::of('P1');
            $red = Ribbon::named('red');
            $parcel->ribbons = [$red];
            $parcel->stamps = [];
            $entities->persist($parcel);
            $entities->persist($red);
            $entities->flush();
        });

        self::assertInstanceOf(Parcel::class, $parcel);
        self::assertSame([
            'INSERT INTO `parcels` (`code`) VALUES (?)',
            'INSERT INTO `ribbons` (`name`) VALUES (?)',
            'INSERT INTO `parcel_ribbon` (`parcel_id`, `ribbon_id`) VALUES (1, 9)',
        ], $this->transaction->statements());
        self::assertSame([1, 9], [$parcel->id, $parcel->ribbons[0]->id]);
        self::assertSame([], $this->link->calls);
    }

    public function test_both_sides_of_a_load_share_the_identity_map_and_select_a_bounded_number_of_statements(): void
    {
        $this->link->queue(
            array_map(static fn (int $id): array => self::parcelRow($id, "P{$id}"), range(1, 1001)),
            array_map(static fn (int $id): array => ['parcel_id' => $id, 'ribbon_id' => $id + 2000], range(1, 1000)),
            [['parcel_id' => 1001, 'ribbon_id' => 3001], ['parcel_id' => 1001, 'ribbon_id' => 2001]],
            array_map(static fn (int $id): array => self::ribbonRow($id, "R{$id}"), range(2001, 3000)),
            [self::ribbonRow(3001, 'R3001')],
        );

        $parcels = $this->entities->repository(Parcel::class)->query()->with('ribbons')->get();

        self::assertSame([
            self::PARCELS,
            self::PARCEL_RIBBON . ' WHERE `parcel_id` IN (' . implode(', ', range(1, 1000)) . ')' . self::BY_PAIR,
            self::PARCEL_RIBBON . ' WHERE `parcel_id` IN (1001)' . self::BY_PAIR,
            self::RIBBONS . ' WHERE `id` IN (' . implode(', ', range(2001, 3000)) . ') ORDER BY `id` ASC',
            self::RIBBONS . ' WHERE `id` IN (3001) ORDER BY `id` ASC',
        ], $this->link->statements(), 'one join select and one target select per 1,000 keys, and no join');
        self::assertSame($parcels[0]->ribbons[0], $parcels[1000]->ribbons[1], 'one row is one object on both sides');
        self::assertSame([3001, 2001], array_column($parcels[1000]->ribbons, 'id'), 'the join rows keep the order they were read in');
    }

    public function test_an_initialized_collection_is_never_overwritten_and_joins_the_next_level(): void
    {
        $this->link->queue([self::ribbonRow(5, 'red')]);
        $red = $this->entities->repository(Ribbon::class)->findOrFail(5);
        $this->link->calls = [];
        $this->link->queue([self::parcelRow(1, 'P1')], [self::parcelRow(1, 'P1')]);
        $parcel = $this->entities->repository(Parcel::class)->query()->first();
        self::assertInstanceOf(Parcel::class, $parcel);
        $parcel->ribbons = [$red];
        $this->link->calls = [];

        $loaded = $this->entities->repository(Parcel::class)->query()->with('ribbons')->get();

        self::assertSame([$parcel], $loaded);
        self::assertSame([$red], $parcel->ribbons);
        self::assertSame([['sql' => self::PARCELS, 'params' => []]], $this->link->calls, 'an initialized collection sends no select');
    }

    public function test_a_collection_holding_an_entity_the_manager_does_not_hold_is_refused(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')], [self::parcelRow(1, 'P1')]);
        $parcel = $this->entities->repository(Parcel::class)->query()->first();
        self::assertInstanceOf(Parcel::class, $parcel);
        $parcel->ribbons = [Ribbon::named('detached')];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::relationTargetNotHeld(Parcel::class, 'ribbons')->getMessage(),
        );

        $this->entities->repository(Parcel::class)->query()->with('ribbons')->get();
    }

    public function test_a_join_row_naming_a_missing_target_row_is_refused(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')], [['parcel_id' => 1, 'ribbon_id' => 5]], []);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            MappingException::missingJoinTarget(Parcel::class, 'ribbons', 'parcel_ribbon', Ribbon::class)->getMessage(),
        );

        $this->entities->repository(Parcel::class)->query()->with('ribbons')->first();
    }

    public function test_a_join_row_without_an_identifier_is_refused(): void
    {
        $this->link->queue([self::parcelRow(1, 'P1')], [['parcel_id' => 1, 'ribbon_id' => null]]);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            MappingException::invalidJoinRow(Parcel::class, 'ribbons', 'parcel_ribbon', 'ribbon_id')->getMessage(),
        );

        $this->entities->repository(Parcel::class)->query()->with('ribbons')->first();
    }

    public function test_a_join_collection_maps_no_column_so_no_predicate_names_it(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(MappingException::notAColumn(Parcel::class, 'ribbons')->getMessage());

        $this->entities->repository(Parcel::class)->query()->where('ribbons', '=', 5);
    }

    public function test_a_string_identifier_binds_the_inverse_join_column_as_a_parameter(): void
    {
        $this->link->queue(
            [self::parcelRow(1, 'P1')],
            [['parcel_id' => 1, 'stamp_code' => 'A-1']],
            [['code' => 'A-1', 'issuer' => 'post']],
        );
        $parcel = $this->entities->repository(Parcel::class)->query()->with('stamps')->first();
        self::assertInstanceOf(Parcel::class, $parcel);
        self::assertSame([
            self::PARCELS . ' LIMIT 1',
            'SELECT `parcel_id`, `stamp_code` FROM `parcel_stamp` WHERE `parcel_id` IN (1) ORDER BY `parcel_id` ASC, `stamp_code` ASC',
            self::STAMPS . ' WHERE `code` IN (?) ORDER BY `code` ASC',
        ], $this->link->statements());

        $issued = Stamp::coded('B-2', 'courier');
        $this->entities->persist($issued);
        $parcel->stamps = [$issued];
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'DELETE FROM `parcel_stamp` WHERE `parcel_id` = ? AND `stamp_code` = ?', 'params' => [1, 'A-1']],
            ['sql' => 'INSERT INTO `stamps` (`code`, `issuer`) VALUES (?, ?)', 'params' => ['B-2', 'courier']],
            ['sql' => 'INSERT INTO `parcel_stamp` (`parcel_id`, `stamp_code`) VALUES (?, ?)', 'params' => [1, 'B-2']],
        ], $this->transaction->calls);
    }

    public function test_a_self_referential_mapping_reads_and_writes_its_two_columns_in_each_direction(): void
    {
        $this->link->queue(
            [self::peerRow(1, 'a')],
            [['peer_id' => 1, 'linked_id' => 2]],
            [self::peerRow(2, 'b')],
            [['linked_id' => 1, 'peer_id' => 3]],
            [self::peerRow(3, 'c')],
        );

        $peer = $this->entities->repository(Peer::class)->query()->with('links', 'linkedBy')->first();

        self::assertInstanceOf(Peer::class, $peer);
        self::assertSame([
            'SELECT `id`, `name` FROM `peers` LIMIT 1',
            'SELECT `peer_id`, `linked_id` FROM `peer_link` WHERE `peer_id` IN (1) ORDER BY `peer_id` ASC, `linked_id` ASC',
            'SELECT `id`, `name` FROM `peers` WHERE `id` IN (2) ORDER BY `id` ASC',
            'SELECT `linked_id`, `peer_id` FROM `peer_link` WHERE `linked_id` IN (1) ORDER BY `linked_id` ASC, `peer_id` ASC',
            'SELECT `id`, `name` FROM `peers` WHERE `id` IN (3) ORDER BY `id` ASC',
        ], $this->link->statements());
        self::assertSame([2], array_column($peer->links, 'id'));
        self::assertSame([3], array_column($peer->linkedBy, 'id'));

        $peer->links = [...$peer->links, $peer];
        $peer->linkedBy = [];
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();

        self::assertSame(
            [['sql' => 'INSERT INTO `peer_link` (`peer_id`, `linked_id`) VALUES (1, 1)', 'params' => []]],
            $this->transaction->calls,
            'the owning column takes the owner and the inverse column the target, and the inverse side stays inert',
        );
    }

    /** One parcel with two loaded ribbons: the membership a flush diffs against. */
    private function loadParcel(): Parcel
    {
        $this->link->queue(
            [self::parcelRow(1, 'P1')],
            [['parcel_id' => 1, 'ribbon_id' => 5], ['parcel_id' => 1, 'ribbon_id' => 6]],
            [self::ribbonRow(5, 'red'), self::ribbonRow(6, 'blue')],
        );
        $parcel = $this->entities->repository(Parcel::class)->query()->with('ribbons')->first();
        self::assertInstanceOf(Parcel::class, $parcel);
        self::assertSame([
            self::PARCELS . ' LIMIT 1',
            self::PARCEL_RIBBON . ' WHERE `parcel_id` IN (1)' . self::BY_PAIR,
            self::RIBBONS . ' WHERE `id` IN (5, 6) ORDER BY `id` ASC',
        ], $this->link->statements());
        $this->link->calls = [];

        return $parcel;
    }

    /**
     * @return array<string, int|string>
     */
    private static function parcelRow(int $id, string $code): array
    {
        return ['id' => $id, 'code' => $code];
    }

    /**
     * @return array<string, int|string>
     */
    private static function ribbonRow(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    /**
     * @return array<string, int|string>
     */
    private static function peerRow(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }

    private static function insertId(int $id): SqlResult
    {
        return new BufferedSqlResult([], 1, null, $id);
    }
}
