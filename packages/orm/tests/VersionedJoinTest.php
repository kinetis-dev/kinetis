<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Manifest;
use Kinetis\Orm\Tests\Fixtures\Pallet;
use Kinetis\Orm\Tests\Fixtures\Parcel;
use Kinetis\Orm\Tests\Fixtures\Ribbon;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\Stamp;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\TestCase;

/**
 * An owning #[ManyToMany] collection on an entity carrying #[Version],
 * against a scripted client and transaction: which changes advance the
 * owner's version, the statement order that carries them, and what a stale
 * version leaves behind. JoinRelationshipTest holds the same contract for
 * an unversioned owner, whose membership writes join rows alone.
 */
final class VersionedJoinTest extends TestCase
{
    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->entities = OrmFactory::create($this->link, MetadataRegistry::fromClasses([
            Manifest::class,
            Pallet::class,
            Parcel::class,
            Ribbon::class,
            Stamp::class,
        ]))->open();
    }

    public function test_a_changed_membership_alone_sends_its_unlink_then_a_version_only_update_then_its_link(): void
    {
        $manifest = $this->loadManifest();
        $gold = $this->ribbon(8, 'gold');
        $manifest->ribbons = [$manifest->ribbons[1], $gold];
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1 AND `ribbon_id` = 5',
            'UPDATE `manifests` SET `version` = 4 WHERE `id` = 1 AND `version` = 3',
            'INSERT INTO `manifest_ribbon` (`manifest_id`, `ribbon_id`) VALUES (1, 8)',
        ], $this->transaction->statements(), 'the owner takes its lock after the unlinks and before the links');
        self::assertSame(4, $manifest->version, 'COMMIT applied the version the UPDATE wrote');

        $this->entities->flush();

        self::assertSame(1, $this->link->begins, 'the committed membership and version are the next flush\'s baseline');
    }

    public function test_a_stale_version_rolls_the_unlink_back_sends_no_link_and_leaves_the_same_work_pending(): void
    {
        $manifest = $this->loadManifest();
        $gold = $this->ribbon(8, 'gold');
        $manifest->ribbons = [$manifest->ribbons[1], $gold];
        $this->transaction->queue(self::affected(1), self::affected(0));

        try {
            $this->entities->flush();
            self::fail('The stale membership was accepted.');
        } catch (OptimisticLockException $e) {
            self::assertSame(
                OptimisticLockException::stale(Manifest::class, 'UPDATE')->getMessage(),
                $e->getMessage(),
            );
        }

        self::assertSame([
            'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1 AND `ribbon_id` = 5',
            'UPDATE `manifests` SET `version` = 4 WHERE `id` = 1 AND `version` = 3',
        ], $this->transaction->statements(), 'the conflict answers before the link INSERT is sent');
        self::assertSame(['rollback'], $this->transaction->ends, 'the rollback takes the unlink with it');
        self::assertSame(3, $manifest->version, 'nothing was applied');

        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1 AND `ribbon_id` = 5',
            'UPDATE `manifests` SET `version` = 4 WHERE `id` = 1 AND `version` = 3',
            'INSERT INTO `manifest_ribbon` (`manifest_id`, `ribbon_id`) VALUES (1, 8)',
        ], $retry->statements(), 'the retry sends the same statements against the same version');
    }

    public function test_a_changed_column_and_a_changed_membership_share_one_update_and_one_increment(): void
    {
        $manifest = $this->loadManifest();
        $manifest->code = 'M2';
        $manifest->ribbons = [$manifest->ribbons[0]];
        $this->transaction->queue(self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1 AND `ribbon_id` = 6', 'params' => []],
            ['sql' => 'UPDATE `manifests` SET `code` = ?, `version` = ? WHERE `id` = ? AND `version` = ?', 'params' => ['M2', 4, 1, 3]],
        ], $this->transaction->calls, 'the membership rides the UPDATE the changed column already sends');
        self::assertSame(4, $manifest->version);
    }

    public function test_two_changed_owning_collections_advance_the_version_once(): void
    {
        $manifest = $this->loadManifest(pallets: true);
        $manifest->ribbons = [$manifest->ribbons[0]];
        $manifest->pallets = [];
        $this->transaction->queue(self::affected(1), self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `manifest_pallet` WHERE `manifest_id` = 1 AND `pallet_id` = 4',
            'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1 AND `ribbon_id` = 6',
            'UPDATE `manifests` SET `version` = 4 WHERE `id` = 1 AND `version` = 3',
        ], $this->transaction->statements(), 'one owner is one row, so its version moves once whatever changed');
    }

    public function test_a_reordered_or_uninitialized_membership_writes_nothing_and_keeps_the_version(): void
    {
        $manifest = $this->loadManifest();
        $manifest->ribbons = array_reverse($manifest->ribbons);

        $this->entities->flush();

        self::assertSame(0, $this->link->begins, 'a set reordered is the same set, and an untouched collection is no change');
        self::assertSame(3, $manifest->version);
    }

    public function test_a_changed_inverse_collection_advances_no_version(): void
    {
        $pallet = $this->loadPallet();
        $pallet->manifests = [];

        $this->entities->flush();

        self::assertSame(0, $this->link->begins, 'the inverse side of a join table is inert, versioned or not');
        self::assertSame(2, $pallet->version);
    }

    public function test_a_new_versioned_owner_inserts_its_version_and_links_without_an_update(): void
    {
        $red = $this->ribbon(5, 'red');
        $manifest = Manifest::of(2, 'M2', 1);
        $manifest->ribbons = [$red];
        $manifest->pallets = [];
        $this->entities->persist($manifest);
        $this->transaction->queue(self::affected(1), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `manifests` (`id`, `code`, `version`) VALUES (?, ?, ?)', 'params' => [2, 'M2', 1]],
            ['sql' => 'INSERT INTO `manifest_ribbon` (`manifest_id`, `ribbon_id`) VALUES (2, 5)', 'params' => []],
        ], $this->transaction->calls, 'the INSERT writes the initial version and the links follow it');
        self::assertSame(1, $manifest->version);
    }

    public function test_removing_a_versioned_owner_unlinks_before_its_versioned_delete_and_sends_no_update(): void
    {
        $manifest = $this->loadManifest();
        $this->entities->remove($manifest);
        $this->transaction->queue(self::affected(2), self::affected(0), self::affected(1));

        $this->entities->flush();

        self::assertSame([
            'DELETE FROM `manifest_pallet` WHERE `manifest_id` = 1',
            'DELETE FROM `manifest_ribbon` WHERE `manifest_id` = 1',
            'DELETE FROM `manifests` WHERE `id` = 1 AND `version` = 3',
        ], $this->transaction->statements(), 'the DELETE carries the lock, so no UPDATE stands between the unlinks and it');
        self::assertFalse($this->entities->contains($manifest));
    }

    public function test_an_exhausted_version_refuses_a_membership_change_before_sql(): void
    {
        $manifest = $this->loadManifest(version: PHP_INT_MAX);
        $manifest->ribbons = [$manifest->ribbons[0]];

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage(
            InvalidEntityStateException::versionExhausted(Manifest::class)->getMessage(),
        );

        try {
            $this->entities->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
        }
    }

    /** Manifest 1 with ribbons 5 and 6 loaded, and pallet 4 when $pallets. */
    private function loadManifest(bool $pallets = false, int $version = 3): Manifest
    {
        $this->link->queue(
            [['id' => 1, 'code' => 'M1', 'version' => $version]],
            [['manifest_id' => 1, 'ribbon_id' => 5], ['manifest_id' => 1, 'ribbon_id' => 6]],
            [['id' => 5, 'name' => 'red'], ['id' => 6, 'name' => 'blue']],
            ...($pallets ? [
                [['manifest_id' => 1, 'pallet_id' => 4]],
                [['id' => 4, 'label' => 'A', 'version' => 2]],
            ] : []),
        );
        $query = $this->entities->repository(Manifest::class)->query();
        $manifest = ($pallets ? $query->with('ribbons', 'pallets') : $query->with('ribbons'))->first();
        self::assertInstanceOf(Manifest::class, $manifest);
        self::assertSame([5, 6], array_column($manifest->ribbons, 'id'));
        $this->link->calls = [];

        return $manifest;
    }

    /** Pallet 4 with manifest 1 read from the inverse end. */
    private function loadPallet(): Pallet
    {
        $this->link->queue(
            [['id' => 4, 'label' => 'A', 'version' => 2]],
            [['pallet_id' => 4, 'manifest_id' => 1]],
            [['id' => 1, 'code' => 'M1', 'version' => 3]],
        );
        $pallet = $this->entities->repository(Pallet::class)->query()->with('manifests')->first();
        self::assertInstanceOf(Pallet::class, $pallet);
        self::assertSame([1], array_column($pallet->manifests, 'id'));
        $this->link->calls = [];

        return $pallet;
    }

    private function ribbon(int $id, string $name): Ribbon
    {
        $this->link->queue([['id' => $id, 'name' => $name]]);
        $ribbon = $this->entities->repository(Ribbon::class)->findOrFail($id);
        $this->link->calls = [];

        return $ribbon;
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }
}
