<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use ArrayIterator;
use Kinetis\Orm\Date;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\Tests\Fixtures\Account;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleCategory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Author;
use Kinetis\Orm\Tests\Fixtures\Booking;
use Kinetis\Orm\Tests\Fixtures\Charter;
use Kinetis\Orm\Tests\Fixtures\Comment;
use Kinetis\Orm\Tests\Fixtures\Crate;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Edition;
use Kinetis\Orm\Tests\Fixtures\Event;
use Kinetis\Orm\Tests\Fixtures\Invoice;
use Kinetis\Orm\Tests\Fixtures\Item;
use Kinetis\Orm\Tests\Fixtures\LedgerAccount;
use Kinetis\Orm\Tests\Fixtures\LedgerEntry;
use Kinetis\Orm\Tests\Fixtures\Metric;
use Kinetis\Orm\Tests\Fixtures\Organization;
use Kinetis\Orm\Tests\Fixtures\Parcel;
use Kinetis\Orm\Tests\Fixtures\Part;
use Kinetis\Orm\Tests\Fixtures\Peer;
use Kinetis\Orm\Tests\Fixtures\Post;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\Profile;
use Kinetis\Orm\Tests\Fixtures\Ribbon;
use Kinetis\Orm\Tests\Fixtures\Seal;
use Kinetis\Orm\Tests\Fixtures\Stamp;
use Kinetis\Orm\Tests\Fixtures\Ticket;
use Kinetis\Orm\Tests\Fixtures\Topic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/InvalidEntities.php';

final class MetadataRegistryTest extends TestCase
{
    private const string INVALID = 'Kinetis\\Orm\\Tests\\Fixtures\\Invalid\\';

    public function test_without_overrides_every_name_follows_convention(): void
    {
        self::assertSame(
            ['entities' => [[
                'class' => ArticleCategory::class,
                'table' => 'article_category',
                'connection' => 'default',
                'id' => 'id',
                'generated' => false,
                'version' => null,
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'displayName', 'column' => 'display_name', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
                'inverses' => [],
                'joins' => [],
            ]]],
            MetadataRegistry::fromClasses([ArticleCategory::class])->toArray(),
        );
    }

    public function test_an_explicit_table_column_and_identifier_win_over_convention(): void
    {
        self::assertSame(
            ['entities' => [[
                'class' => Account::class,
                'table' => 'reporting.accounts',
                'connection' => 'default',
                'id' => 'uuid',
                'generated' => false,
                'version' => null,
                'properties' => [
                    ['name' => 'uuid', 'column' => 'account_uuid', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => null],
                    ['name' => 'email', 'column' => 'email_address', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
                'inverses' => [],
                'joins' => [],
            ]]],
            MetadataRegistry::fromClasses([Account::class])->toArray(),
        );
    }

    public function test_a_generated_identifier_is_recorded(): void
    {
        self::assertSame(
            ['entities' => [[
                'class' => Ticket::class,
                'table' => 'tickets',
                'connection' => 'default',
                'id' => 'id',
                'generated' => true,
                'version' => null,
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => null],
                    ['name' => 'subject', 'column' => 'subject', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
                'inverses' => [],
                'joins' => [],
            ]]],
            MetadataRegistry::fromClasses([Ticket::class])->toArray(),
        );
    }

    public function test_an_explicit_version_is_recorded_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Invoice::class])->toArray();

        self::assertSame(
            ['entities' => [[
                'class' => Invoice::class,
                'table' => 'invoices',
                'connection' => 'default',
                'id' => 'id',
                'generated' => false,
                'version' => 'version',
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'status', 'column' => 'status', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'version', 'column' => 'row_version', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'total', 'column' => 'total', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
                'inverses' => [],
                'joins' => [],
            ]]],
            $data,
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_date_time_immutable_property_is_recorded_as_a_timestamp_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Event::class])->toArray();

        self::assertSame(
            [
                ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                ['name' => 'occurredAt', 'column' => 'occurred_at', 'type' => 'timestamp', 'nullable' => false, 'enum' => null, 'target' => null],
                ['name' => 'archivedAt', 'column' => 'archived_at', 'type' => 'timestamp', 'nullable' => true, 'enum' => null, 'target' => null],
            ],
            $data['entities'][0]['properties'],
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_date_property_is_recorded_as_a_date_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Booking::class])->toArray();

        self::assertSame(
            [
                ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                ['name' => 'arrivesOn', 'column' => 'arrives_on', 'type' => 'date', 'nullable' => false, 'enum' => null, 'target' => null],
                ['name' => 'cancelledOn', 'column' => 'cancelled_on', 'type' => 'date', 'nullable' => true, 'enum' => null, 'target' => null],
            ],
            $data['entities'][0]['properties'],
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_relationship_maps_its_foreign_key_as_the_target_identifier_type_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Post::class, Topic::class, Organization::class, Author::class, Charter::class, Comment::class, Profile::class])->toArray();
        $entities = array_column($data['entities'], 'properties', 'class');

        self::assertSame(
            ['name' => 'author', 'column' => 'written_by', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => Author::class],
            $entities[Post::class][2],
        );
        self::assertSame(
            ['name' => 'organization', 'column' => 'organization_id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => Organization::class],
            $entities[Author::class][2],
        );
        self::assertSame(
            ['name' => 'parent', 'column' => 'parent_id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => Topic::class],
            $entities[Topic::class][1],
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_an_owned_inverse_relationship_carries_its_ownership_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Part::class, Seal::class, Item::class, Crate::class])->toArray();
        $entities = array_column($data['entities'], null, 'class');

        self::assertSame([
            ['name' => 'items', 'kind' => 'hasMany', 'target' => Item::class, 'mappedBy' => 'crate', 'nullable' => false, 'owned' => true],
            ['name' => 'seal', 'kind' => 'hasOne', 'target' => Seal::class, 'mappedBy' => 'crate', 'nullable' => true, 'owned' => true],
        ], $entities[Crate::class]['inverses']);
        self::assertSame(
            [['name' => 'parts', 'kind' => 'hasMany', 'target' => Part::class, 'mappedBy' => 'item', 'nullable' => false, 'owned' => true]],
            $entities[Item::class]['inverses'],
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_an_inverse_relationship_maps_no_column_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Topic::class, Profile::class, Post::class, Organization::class, Comment::class, Charter::class, Author::class])->toArray();
        $entities = array_column($data['entities'], null, 'class');

        self::assertSame(['id', 'name', 'organization'], array_column($entities[Author::class]['properties'], 'name'));
        self::assertSame([
            ['name' => 'profile', 'kind' => 'hasOne', 'target' => Profile::class, 'mappedBy' => 'author', 'nullable' => true, 'owned' => false],
            ['name' => 'posts', 'kind' => 'hasMany', 'target' => Post::class, 'mappedBy' => 'author', 'nullable' => false, 'owned' => false],
        ], $entities[Author::class]['inverses']);
        self::assertSame(
            [['name' => 'charter', 'kind' => 'hasOne', 'target' => Charter::class, 'mappedBy' => 'organization', 'nullable' => false, 'owned' => false]],
            $entities[Organization::class]['inverses'],
        );
        self::assertSame(['id', 'parent', 'supersedes'], array_column($entities[Topic::class]['properties'], 'name'));
        self::assertSame([
            ['name' => 'children', 'kind' => 'hasMany', 'target' => Topic::class, 'mappedBy' => 'parent', 'nullable' => false, 'owned' => false],
            ['name' => 'supersededBy', 'kind' => 'hasOne', 'target' => Topic::class, 'mappedBy' => 'supersedes', 'nullable' => true, 'owned' => false],
        ], $entities[Topic::class]['inverses']);
        self::assertSame([], $entities[Profile::class]['inverses']);
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_join_collection_maps_no_column_and_each_side_carries_the_same_table_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Parcel::class, Ribbon::class, Stamp::class, Peer::class])->toArray();
        $entities = array_column($data['entities'], null, 'class');

        self::assertSame(['id', 'code'], array_column($entities[Parcel::class]['properties'], 'name'));
        self::assertSame([
            ['name' => 'ribbons', 'target' => Ribbon::class, 'table' => 'parcel_ribbon', 'joinColumn' => 'parcel_id', 'inverseJoinColumn' => 'ribbon_id', 'mappedBy' => null],
            ['name' => 'stamps', 'target' => Stamp::class, 'table' => 'parcel_stamp', 'joinColumn' => 'parcel_id', 'inverseJoinColumn' => 'stamp_code', 'mappedBy' => null],
        ], $entities[Parcel::class]['joins']);
        self::assertSame(
            [['name' => 'parcels', 'target' => Parcel::class, 'table' => 'parcel_ribbon', 'joinColumn' => 'ribbon_id', 'inverseJoinColumn' => 'parcel_id', 'mappedBy' => 'ribbons']],
            $entities[Ribbon::class]['joins'],
            'the inverse side reads the owning table with its columns swapped',
        );
        self::assertSame([], $entities[Stamp::class]['joins']);
        self::assertSame([
            ['name' => 'links', 'target' => Peer::class, 'table' => 'peer_link', 'joinColumn' => 'peer_id', 'inverseJoinColumn' => 'linked_id', 'mappedBy' => null],
            ['name' => 'linkedBy', 'target' => Peer::class, 'table' => 'peer_link', 'joinColumn' => 'linked_id', 'inverseJoinColumn' => 'peer_id', 'mappedBy' => 'links'],
        ], $entities[Peer::class]['joins'], 'a self-referential mapping reads its own table from either column');
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_property_named_version_without_the_attribute_is_not_a_version(): void
    {
        $entity = MetadataRegistry::fromClasses([Edition::class])->toArray()['entities'][0];

        self::assertNull($entity['version']);
        self::assertSame(['id', 'version'], array_column($entity['properties'], 'name'));
    }

    public function test_every_visibility_type_and_trait_property_is_mapped(): void
    {
        $entity = MetadataRegistry::fromClasses([Article::class])->toArray()['entities'][0];
        $properties = array_column($entity['properties'], null, 'name');

        self::assertSame('articles', $entity['table']);
        self::assertSame(
            ['id', 'title', 'summary', 'status', 'priority', 'featured', 'rating', 'authorId', 'slug'],
            array_keys($properties),
        );
        self::assertSame(['name' => 'summary', 'column' => 'summary', 'type' => 'string', 'nullable' => true, 'enum' => null, 'target' => null], $properties['summary']);
        self::assertSame(['name' => 'status', 'column' => 'status', 'type' => 'string', 'nullable' => false, 'enum' => ArticleStatus::class, 'target' => null], $properties['status']);
        self::assertSame(['name' => 'priority', 'column' => 'priority', 'type' => 'int', 'nullable' => true, 'enum' => Priority::class, 'target' => null], $properties['priority']);
        self::assertSame(['name' => 'featured', 'column' => 'featured', 'type' => 'bool', 'nullable' => false, 'enum' => null, 'target' => null], $properties['featured']);
        self::assertSame(['name' => 'rating', 'column' => 'rating', 'type' => 'float', 'nullable' => false, 'enum' => null, 'target' => null], $properties['rating']);
        self::assertSame('author', $properties['authorId']['column']);
        self::assertSame('slug', $properties['slug']['column']);
    }

    public function test_to_array_is_ordered_by_class_whatever_order_the_classes_arrive_in(): void
    {
        $forward = MetadataRegistry::fromClasses([Article::class, Account::class, Document::class])->toArray();
        $reversed = MetadataRegistry::fromClasses(new ArrayIterator([Document::class, Account::class, Article::class]))->toArray();

        self::assertSame($forward, $reversed);
        self::assertSame([Account::class, Article::class, Document::class], array_column($forward['entities'], 'class'));
    }

    public function test_from_array_accepts_the_exported_form_of_to_array(): void
    {
        $data = MetadataRegistry::fromClasses([Article::class, Account::class, ArticleCategory::class, Ticket::class])->toArray();

        /** @var array<string, mixed> $exported */
        $exported = eval('return ' . var_export($data, true) . ';');

        self::assertSame($data, MetadataRegistry::fromArray($exported)->toArray());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public function test_every_connection_an_entity_names_is_listed_once_in_byte_order(): void
    {
        $classes = [Metric::class, LedgerEntry::class, ArticleCategory::class, LedgerAccount::class, Account::class];
        $registry = MetadataRegistry::fromClasses($classes);

        self::assertSame(['analytics', 'default', 'ledger'], $registry->connections());
        self::assertSame($registry->connections(), MetadataRegistry::fromClasses(array_reverse($classes))->connections());
        self::assertSame(['default'], MetadataRegistry::fromClasses([ArticleCategory::class, Account::class])->connections());
        self::assertSame([], MetadataRegistry::fromClasses([])->connections());
        self::assertSame('analytics', $registry->connectionFor(Metric::class));
        self::assertSame('ledger', $registry->connectionFor(LedgerEntry::class));
        self::assertSame('default', $registry->connectionFor(Account::class));
    }

    public function test_the_connection_of_a_class_outside_the_registry_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Ticket::class . ' is not an entity in this MetadataRegistry.');

        MetadataRegistry::fromClasses([Metric::class])->connectionFor(Ticket::class);
    }

    public function test_a_named_connection_and_a_relationship_on_it_round_trip(): void
    {
        $registry = MetadataRegistry::fromClasses([LedgerEntry::class, Metric::class, LedgerAccount::class]);
        $entities = $registry->toArray()['entities'];

        self::assertSame(
            [LedgerAccount::class => 'ledger', LedgerEntry::class => 'ledger', Metric::class => 'analytics'],
            array_column($entities, 'connection', 'class'),
        );
        self::assertSame(LedgerAccount::class, $entities[1]['properties'][2]['target']);
        self::assertSame($registry->toArray(), MetadataRegistry::fromArray($registry->toArray())->toArray());
    }

    public function test_a_cross_connection_relationship_is_refused_whatever_order_the_classes_arrive_in(): void
    {
        $messages = [];

        foreach ([[self::INVALID . 'CrossJoinInverse', self::INVALID . 'CrossJoinOwning'], [self::INVALID . 'CrossJoinOwning', self::INVALID . 'CrossJoinInverse']] as $classes) {
            try {
                MetadataRegistry::fromClasses($classes);
                self::fail('A relationship across connections was mapped.');
            } catch (MappingException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
        self::assertStringStartsWith(self::INVALID . 'CrossJoinInverse::$owners is not a usable #[ManyToMany]', $messages[0]);
    }

    public static function unmappableClasses(): iterable
    {
        yield 'a non-string entry' => [42, 'int is not an existing class'];
        yield 'a missing class' => [self::INVALID . 'Missing', 'Invalid\\Missing" is not an existing class'];
        yield 'an interface' => [self::INVALID . 'EntityInterface', 'Invalid\\EntityInterface" is not an existing class'];
        yield 'a class without #[Entity]' => [self::INVALID . 'NotMarked', 'NotMarked has no #[Kinetis\\Orm\\Attributes\\Entity] attribute'];
        yield 'an abstract class' => [self::INVALID . 'AbstractEntity', 'AbstractEntity cannot be mapped as an entity: it is abstract'];
        yield 'an enum' => [self::INVALID . 'EnumEntity', 'EnumEntity cannot be mapped as an entity: it is an enum'];
        yield 'a readonly class' => [self::INVALID . 'ReadonlyEntity', 'ReadonlyEntity cannot be mapped as an entity: it is a readonly class'];
        yield 'a class with a parent' => [self::INVALID . 'ChildEntity', 'it extends ' . self::INVALID . 'ParentRecord'];
        yield 'a readonly property' => [self::INVALID . 'ReadonlyProperty', 'ReadonlyProperty::$name cannot be mapped: it is readonly'];
        yield 'a hooked property' => [self::INVALID . 'HookedProperty', 'HookedProperty::$name cannot be mapped: it declares property hooks'];
        yield 'a virtual property' => [self::INVALID . 'VirtualProperty', 'VirtualProperty::$label cannot be mapped: it declares property hooks'];
        yield 'an untyped property' => [self::INVALID . 'UntypedProperty', 'UntypedProperty::$name cannot be mapped: it has no type'];
        yield 'a union type' => [self::INVALID . 'UnionProperty', 'UnionProperty::$code cannot be mapped: it declares the composite type'];
        yield 'an intersection type' => [self::INVALID . 'IntersectionProperty', 'IntersectionProperty::$items cannot be mapped: it declares the composite type'];
        yield 'an array' => [self::INVALID . 'ArrayProperty', 'ArrayProperty::$tags cannot be mapped: it declares array'];
        yield 'a DateTime' => [self::INVALID . 'MutableDateProperty', 'MutableDateProperty::$publishedAt cannot be mapped: it declares DateTime, which is not string, int, float, bool, DateTimeImmutable, ' . Date::class . ' or a backed enum'];
        yield 'a DateTimeInterface' => [self::INVALID . 'DateInterfaceProperty', 'DateInterfaceProperty::$publishedAt cannot be mapped: it declares DateTimeInterface, which'];
        yield 'a DateTimeImmutable subclass' => [self::INVALID . 'DateSubclassProperty', 'DateSubclassProperty::$publishedAt cannot be mapped: it declares ' . self::INVALID . 'LocalDate, which'];
        yield 'a timestamp identifier' => [self::INVALID . 'TimestampIdentifier', 'TimestampIdentifier has no usable identifier: the identifier property "id" must be typed int or string'];
        yield 'a timestamp version' => [self::INVALID . 'TimestampVersion', 'TimestampVersion has no usable version: the version property "version" must be typed int'];
        yield 'a class named Date in another namespace' => [self::INVALID . 'LookalikeDateProperty', 'LookalikeDateProperty::$bookedOn cannot be mapped: it declares ' . self::INVALID . 'Date, which'];
        yield 'a date identifier' => [self::INVALID . 'DateIdentifier', 'DateIdentifier has no usable identifier: the identifier property "id" must be typed int or string'];
        yield 'a date version' => [self::INVALID . 'DateVersion', 'DateVersion has no usable version: the version property "version" must be typed int'];
        yield 'a relationship typed DateTimeImmutable' => [self::INVALID . 'TimestampRelationship', 'TimestampRelationship::$publishedAt is not a usable #[BelongsTo] relationship: DateTimeImmutable is not an entity in this MetadataRegistry'];
        yield 'mixed' => [self::INVALID . 'MixedProperty', 'MixedProperty::$data cannot be mapped: it declares mixed'];
        yield 'a unit enum' => [self::INVALID . 'UnitEnumProperty', 'UnitEnumProperty::$suit cannot be mapped: it declares ' . self::INVALID . 'Suit'];
        yield 'no identifier' => [self::INVALID . 'MissingIdentifier', 'no property carries #[Id] and none is named "id"'];
        yield 'two identifiers' => [self::INVALID . 'TwoIdentifiers', 'more than one property carries #[Id]: first, second'];
        yield 'a float identifier' => [self::INVALID . 'FloatIdentifier', 'the identifier property "id" must be typed int or string'];
        yield 'an enum identifier' => [self::INVALID . 'EnumIdentifier', 'the identifier property "id" must be typed int or string'];
        yield 'a generated string identifier' => [self::INVALID . 'GeneratedStringIdentifier', 'the generated identifier property "id" must be typed ?int'];
        yield 'a generated non-nullable identifier' => [self::INVALID . 'GeneratedNonNullableIdentifier', 'the generated identifier property "id" must be typed ?int'];
        yield 'a nullable version' => [self::INVALID . 'NullableVersion', 'NullableVersion has no usable version: the version property "version" must be typed int'];
        yield 'a string version' => [self::INVALID . 'StringVersion', 'StringVersion has no usable version: the version property "version" must be typed int'];
        yield 'an int-backed enum version' => [self::INVALID . 'EnumVersion', 'EnumVersion has no usable version: the version property "version" must be typed int'];
        yield 'two versions' => [self::INVALID . 'TwoVersions', 'TwoVersions has no usable version: more than one property carries #[Version]: first, second'];
        yield 'a version that is the identifier' => [self::INVALID . 'IdentifierVersion', 'IdentifierVersion has no usable version: the identifier property "id" cannot also be the version'];
        yield 'a table name with a dash' => [self::INVALID . 'InvalidTable', 'maps to the table "article-list"'];
        yield 'an empty table name' => [self::INVALID . 'EmptyTable', 'maps to the table ""'];
        yield 'a table name ending in a dot' => [self::INVALID . 'TrailingDotTable', 'maps to the table "reporting."'];
        yield 'a column name with a space' => [self::INVALID . 'InvalidColumn', 'InvalidColumn::$name maps to the column "first name"'];
        yield 'an empty column name' => [self::INVALID . 'EmptyColumn', 'EmptyColumn::$name maps to the column ""'];
        yield 'columns differing only by case' => [self::INVALID . 'DuplicateColumn', 'DuplicateColumn::$id and ' . self::INVALID . 'DuplicateColumn::$legacyId both map to the column "ID"'];
        yield 'a relationship typed with a scalar' => [self::INVALID . 'RelationshipScalar', 'RelationshipScalar::$categoryId is not a usable #[BelongsTo] relationship: it declares int, which is not an entity class'];
        yield 'a relationship carrying #[Column]' => [self::INVALID . 'RelationshipWithColumn', 'RelationshipWithColumn::$category is not a usable #[BelongsTo] relationship: it also carries #[Column]'];
        yield 'a relationship carrying #[Id]' => [self::INVALID . 'RelationshipIdentifier', 'RelationshipIdentifier::$category is not a usable #[BelongsTo] relationship: it also carries #[Id]'];
        yield 'a relationship carrying #[Version]' => [self::INVALID . 'RelationshipVersion', 'RelationshipVersion::$category is not a usable #[BelongsTo] relationship: it also carries #[Version]'];
        yield 'a relationship with a default value' => [self::INVALID . 'RelationshipDefault', 'RelationshipDefault::$category is not a usable #[BelongsTo] relationship: it declares a default value'];
        yield 'a relationship column with a space' => [self::INVALID . 'RelationshipInvalidColumn', 'RelationshipInvalidColumn::$category maps to the column "category id"'];
        yield 'a relationship column another property maps' => [self::INVALID . 'RelationshipDuplicateColumn', 'RelationshipDuplicateColumn::$categoryId and ' . self::INVALID . 'RelationshipDuplicateColumn::$category both map to the column "category_id"'];
        yield 'a relationship to a class outside the registry' => [self::INVALID . 'RelationshipUnknownTarget', 'RelationshipUnknownTarget::$document is not a usable #[BelongsTo] relationship: ' . Document::class . ' is not an entity in this MetadataRegistry'];
        yield 'a relationship named id' => [self::INVALID . 'RelationshipIdentifierByName', 'the identifier property "id" must be typed int or string'];

        $inverse = ' is not a usable #[HasOne] or #[HasMany] relationship: ';

        yield 'a #[HasMany] with a default value' => [self::INVALID . 'InverseDefaultList', 'InverseDefaultList::$children' . $inverse . 'it declares a default value'];
        yield 'a #[HasOne] with a default value' => [self::INVALID . 'InverseDefaultNull', 'InverseDefaultNull::$child' . $inverse . 'it declares a default value'];
        yield 'a #[HasOne] typed with a scalar' => [self::INVALID . 'HasOneScalar', 'HasOneScalar::$childId' . $inverse . '#[HasOne] needs a type naming one entity class, and it declares ?int'];
        yield 'a #[HasOne] typed array' => [self::INVALID . 'HasOneArray', 'HasOneArray::$children' . $inverse . '#[HasOne] needs a type naming one entity class, and it declares array'];
        yield 'a nullable #[HasMany]' => [self::INVALID . 'HasManyNullable', 'HasManyNullable::$children' . $inverse . '#[HasMany] needs the type array, and it declares ?array'];
        yield 'a #[HasMany] typed with an entity class' => [self::INVALID . 'HasManyObject', 'HasManyObject::$category' . $inverse . '#[HasMany] needs the type array, and it declares ' . ArticleCategory::class];
        yield 'both inverse attributes' => [self::INVALID . 'InverseBoth', 'InverseBoth::$child' . $inverse . 'it carries both #[HasOne] and #[HasMany]'];
        yield 'an inverse relationship carrying #[BelongsTo]' => [self::INVALID . 'InverseBelongsTo', 'InverseBelongsTo::$child' . $inverse . 'it also carries #[BelongsTo]'];
        yield 'an inverse relationship carrying #[Column]' => [self::INVALID . 'InverseWithColumn', 'InverseWithColumn::$children' . $inverse . 'it also carries #[Column]'];
        yield 'an inverse relationship carrying #[Id]' => [self::INVALID . 'InverseIdentifier', 'InverseIdentifier::$twin' . $inverse . 'it also carries #[Id]'];
        yield 'an inverse relationship carrying #[Version]' => [self::INVALID . 'InverseVersion', 'InverseVersion::$children' . $inverse . 'it also carries #[Version]'];
        yield 'a readonly inverse relationship' => [self::INVALID . 'InverseReadonly', 'InverseReadonly::$children cannot be mapped: it is readonly'];
        yield 'a #[HasMany] target outside the registry' => [self::INVALID . 'HasManyUnknownTarget', 'HasManyUnknownTarget::$documents' . $inverse . Document::class . ' is not an entity in this MetadataRegistry'];
        yield 'a #[HasOne] target outside the registry' => [self::INVALID . 'HasOneUnknownTarget', 'HasOneUnknownTarget::$document' . $inverse . Document::class . ' is not an entity in this MetadataRegistry'];
        yield 'an unknown mappedBy' => [self::INVALID . 'InverseUnknownMappedBy', 'InverseUnknownMappedBy::$children' . $inverse . 'mappedBy names "parent", which is not a mapped property of ' . self::INVALID . 'InverseUnknownMappedBy'];
        yield 'a mappedBy naming an inverse relationship' => [self::INVALID . 'InverseMappedByInverse', 'InverseMappedByInverse::$children' . $inverse . 'mappedBy names "children", which is not a mapped property'];
        yield 'a mappedBy naming a scalar property' => [self::INVALID . 'InverseScalarMappedBy', 'InverseScalarMappedBy::$children' . $inverse . 'mappedBy names ' . self::INVALID . 'InverseScalarMappedBy::$parent, which is not a #[BelongsTo] relationship'];
        yield 'a mappedBy referencing another class' => [
            [self::INVALID . 'InverseWrongDirection', self::INVALID . 'InverseChild'],
            'InverseWrongDirection::$children' . $inverse . 'mappedBy names ' . self::INVALID . 'InverseChild::$category, which references ' . ArticleCategory::class . ', not ' . self::INVALID . 'InverseWrongDirection',
        ];
        yield 'two owned inverse relationships over one #[BelongsTo]' => [
            [self::INVALID . 'OwnedTwice', self::INVALID . 'OwnedOnce'],
            'OwnedTwice::$first' . $inverse . 'it and ' . self::INVALID . 'OwnedTwice::$children both own ' . self::INVALID
                . 'OwnedOnce::$owner, and one #[BelongsTo] property has at most one owned inverse relationship',
        ];
        yield 'two owners of one entity class' => [
            [self::INVALID . 'OwnedChild', self::INVALID . 'OwnedFirst', self::INVALID . 'OwnedSecond'],
            'OwnedSecond::$children' . $inverse . self::INVALID . 'OwnedChild is already owned through ' . self::INVALID
                . 'OwnedFirst::$children, and an entity class has at most one owned inverse relationship in a MetadataRegistry',
        ];
        $join = ' is not a usable #[ManyToMany] relationship: ';

        yield 'a #[ManyToMany] with a default value' => [self::INVALID . 'JoinDefault', 'JoinDefault::$categories' . $join . 'it declares a default value'];
        yield 'a nullable #[ManyToMany]' => [self::INVALID . 'JoinNullable', 'JoinNullable::$categories' . $join . '#[ManyToMany] needs the type array, and it declares ?array'];
        yield 'a #[ManyToMany] typed with an entity class' => [self::INVALID . 'JoinObject', 'JoinObject::$category' . $join . '#[ManyToMany] needs the type array, and it declares ' . ArticleCategory::class];
        yield 'a #[ManyToMany] carrying #[HasMany]' => [self::INVALID . 'JoinWithHasMany', 'JoinWithHasMany::$categories' . $join . 'it also carries #[HasMany]'];
        yield 'a #[ManyToMany] carrying #[Column]' => [self::INVALID . 'JoinWithColumn', 'JoinWithColumn::$categories' . $join . 'it also carries #[Column]'];
        yield 'an owning side without a table' => [self::INVALID . 'JoinWithoutTable', 'JoinWithoutTable::$categories' . $join . 'an owning side names table, joinColumn and inverseJoinColumn'];
        yield 'an inverse side naming a table' => [self::INVALID . 'JoinInverseWithTable', 'JoinInverseWithTable::$categories' . $join . 'an inverse side names mappedBy alone'];
        yield 'a join table with a dash' => [self::INVALID . 'JoinInvalidTable', 'JoinInvalidTable::$categories' . $join . 'the join table "join-table" is not one or more identifiers separated by dots'];
        yield 'a join column with a space' => [self::INVALID . 'JoinInvalidColumn', 'JoinInvalidColumn::$categories' . $join . 'the join column "a id" is not an identifier'];
        yield 'one column for both ends' => [self::INVALID . 'JoinOneColumn', 'JoinOneColumn::$peers' . $join . 'joinColumn and inverseJoinColumn both name "peer_id"'];
        yield 'a #[ManyToMany] target outside the registry' => [self::INVALID . 'JoinUnknownTarget', 'JoinUnknownTarget::$documents' . $join . Document::class . ' is not an entity in this MetadataRegistry'];
        yield 'an unknown join mappedBy' => [self::INVALID . 'JoinUnknownMappedBy', 'JoinUnknownMappedBy::$mirrors' . $join . 'mappedBy names "peers", which is not a #[ManyToMany] property'];
        yield 'a join mappedBy naming an inverse side' => [self::INVALID . 'JoinMappedByInverse', 'JoinMappedByInverse::$mirrors, which is itself an inverse #[ManyToMany] relationship'];
        yield 'a join mappedBy referencing another class' => [
            [self::INVALID . 'JoinWrongDirection', self::INVALID . 'JoinElsewhere'],
            'JoinWrongDirection::$others' . $join . 'mappedBy names ' . self::INVALID . 'JoinElsewhere::$categories, which references ' . ArticleCategory::class . ', not ' . self::INVALID . 'JoinWrongDirection',
        ];

        yield 'an uppercase connection' => [self::INVALID . 'UppercaseConnection', 'UppercaseConnection names the connection "Reporting", which is not a connection name: lowercase ASCII letters and digits, starting with a letter.'];
        yield 'a connection with an underscore' => [self::INVALID . 'UnderscoreConnection', 'UnderscoreConnection names the connection "report_ing", which is not a connection name'];
        yield 'a connection starting with a digit' => [self::INVALID . 'DigitFirstConnection', 'DigitFirstConnection names the connection "2nd", which is not a connection name'];
        yield 'the reserved connection app' => [self::INVALID . 'AppConnection', 'AppConnection names the connection "app", which is reserved: its scoped DB_NAME key would be DB_APP_NAME, the default connection\'s application-name key. Name the connection otherwise.'];
        yield 'an empty connection' => [self::INVALID . 'EmptyConnection', 'EmptyConnection names the connection "", which is not a connection name'];

        $crossing = static fn (string $source, string $sourceConnection, string $target, string $targetConnection): string => "{$source} is on the \"{$sourceConnection}\" connection and {$target} on the \"{$targetConnection}\" connection, and a relationship never spans two connections.";

        yield 'a #[BelongsTo] across connections' => [
            self::INVALID . 'CrossBelongsTo',
            self::INVALID . 'CrossBelongsTo::$category is not a usable #[BelongsTo] relationship: ' . $crossing(self::INVALID . 'CrossBelongsTo', 'ledger', ArticleCategory::class, 'default'),
        ];
        yield 'a #[HasMany] across connections' => [
            [self::INVALID . 'CrossHasManyTarget', self::INVALID . 'CrossHasManyOwner'],
            self::INVALID . 'CrossHasManyOwner::$targets' . $inverse . $crossing(self::INVALID . 'CrossHasManyOwner', 'ledger', self::INVALID . 'CrossHasManyTarget', 'default'),
        ];
        yield 'a #[HasOne] across connections' => [
            [self::INVALID . 'CrossHasOneTarget', self::INVALID . 'CrossHasOneOwner'],
            self::INVALID . 'CrossHasOneOwner::$target' . $inverse . $crossing(self::INVALID . 'CrossHasOneOwner', 'ledger', self::INVALID . 'CrossHasOneTarget', 'default'),
        ];
        yield 'an owning #[ManyToMany] across connections' => [
            self::INVALID . 'CrossJoinOwner',
            self::INVALID . 'CrossJoinOwner::$categories' . $join . $crossing(self::INVALID . 'CrossJoinOwner', 'ledger', ArticleCategory::class, 'default'),
        ];
        yield 'an inverse #[ManyToMany] across connections' => [
            [self::INVALID . 'CrossJoinOwning', self::INVALID . 'CrossJoinInverse'],
            self::INVALID . 'CrossJoinInverse::$owners' . $join . $crossing(self::INVALID . 'CrossJoinInverse', 'ledger', self::INVALID . 'CrossJoinOwning', 'default'),
        ];

        yield 'a mappedBy of its own class referencing another class' => [
            self::INVALID . 'InverseWrongSelf',
            'InverseWrongSelf::$twin' . $inverse . 'mappedBy names ' . self::INVALID . 'InverseWrongSelf::$category, which references ' . ArticleCategory::class . ', not ' . self::INVALID . 'InverseWrongSelf',
        ];
    }

    /**
     * @param mixed $class one class or a list of classes mapped with ArticleCategory
     */
    #[DataProvider('unmappableClasses')]
    public function test_an_unmappable_class_is_refused(mixed $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        MetadataRegistry::fromClasses([ArticleCategory::class, ...(is_array($class) ? $class : [$class])]);
    }

    public function test_a_class_listed_twice_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Document::class . ' is listed more than once');

        MetadataRegistry::fromClasses([Document::class, Account::class, Document::class]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function unusableMetadata(): iterable
    {
        $valid = MetadataRegistry::fromClasses([Account::class, ArticleCategory::class])->toArray();
        [$account, $category] = $valid['entities'];

        $renamed = $category;
        $renamed['properties'][1]['column'] = 'label';
        $retyped = $category;
        $retyped['properties'][0]['type'] = 'string';
        $nullable = $category;
        $nullable['properties'][1]['nullable'] = true;
        $timed = $category;
        $timed['properties'][1]['type'] = 'timestamp';
        $untimed = MetadataRegistry::fromClasses([Event::class])->toArray()['entities'][0];
        $untimed['properties'][1]['type'] = 'string';
        $dated = $category;
        $dated['properties'][1]['type'] = 'date';
        $booking = MetadataRegistry::fromClasses([Booking::class])->toArray()['entities'][0];
        $undated = $booking;
        $undated['properties'][1]['type'] = 'string';
        $retimed = $booking;
        $retimed['properties'][2]['type'] = 'timestamp';
        $miscased = $booking;
        $miscased['properties'][1]['type'] = 'Date';
        $reidentified = $account;
        $reidentified['id'] = 'id';
        $generated = $category;
        $generated['generated'] = true;
        $retabled = $category;
        $retabled['table'] = 'categories';
        $rerouted = $category;
        $rerouted['connection'] = 'analytics';
        $unrouted = $category;
        unset($unrouted['connection']);
        $metric = MetadataRegistry::fromClasses([Metric::class])->toArray()['entities'][0];
        $defaulted = $metric;
        $defaulted['connection'] = 'default';
        $moved = $metric;
        $moved['connection'] = 'ledger';
        $unversioned = $category;
        unset($unversioned['version']);
        $versioned = $category;
        $versioned['version'] = 'displayName';
        $invoice = MetadataRegistry::fromClasses([Invoice::class])->toArray()['entities'][0];
        $dropped = $invoice;
        $dropped['version'] = null;
        $uninversed = $category;
        unset($uninversed['inverses']);
        $invented = $category;
        $invented['inverses'] = [['name' => 'displayName', 'kind' => 'hasOne', 'target' => ArticleCategory::class, 'mappedBy' => 'id', 'nullable' => false]];
        [$author, $charter, $comment, $organization, $post, $profile] = MetadataRegistry::fromClasses(
            [Author::class, Charter::class, Comment::class, Organization::class, Post::class, Profile::class],
        )->toArray()['entities'];
        $graph = static fn (array $author): array => ['entities' => [$author, $charter, $comment, $organization, $post, $profile]];
        $retargeted = $author;
        $retargeted['properties'][2]['target'] = Account::class;
        $untargeted = $author;
        unset($untargeted['properties'][2]['target']);
        $scalar = $author;
        $scalar['properties'][2]['target'] = null;
        $unlisted = $author;
        $unlisted['inverses'] = [$author['inverses'][0]];
        $reordered = $author;
        $reordered['inverses'] = array_reverse($author['inverses']);
        $rekinded = $author;
        $rekinded['inverses'][1]['kind'] = 'hasOne';
        $reinversed = $author;
        $reinversed['inverses'][0]['target'] = Organization::class;
        $remapped = $author;
        $remapped['inverses'][1]['mappedBy'] = 'title';
        $renulled = $author;
        $renulled['inverses'][0]['nullable'] = false;
        $unnulled = $author;
        unset($unnulled['inverses'][0]['nullable']);
        $extended = $author;
        $extended['inverses'][0]['column'] = 'author_id';
        [$parcel, $ribbon, $stamp] = MetadataRegistry::fromClasses([Parcel::class, Ribbon::class, Stamp::class])->toArray()['entities'];
        $parcels = static fn (array $parcel): array => ['entities' => [$parcel, $ribbon, $stamp]];
        $unjoined = $category;
        unset($unjoined['joins']);
        $rejoined = $parcel;
        $rejoined['joins'][0]['table'] = 'ribbon_parcel';
        $recolumned = $parcel;
        $recolumned['joins'][1]['inverseJoinColumn'] = 'stamp_id';
        $reowned = $ribbon;
        $reowned['joins'][0]['mappedBy'] = null;

        yield 'an empty array' => [[], 'must hold exactly one "entities" list'];
        yield 'a second top-level field' => [[...$valid, 'version' => 1], 'must hold exactly one "entities" list'];
        yield 'entities that are not a list' => [['entities' => ['account' => $account]], 'must hold exactly one "entities" list'];
        yield 'an entry that is not an array' => [['entities' => ['Account']], 'every entry needs a "class" string'];
        yield 'an entry without a class' => [['entities' => [['table' => 'accounts']]], 'every entry needs a "class" string'];
        yield 'a class that no longer exists' => [['entities' => [[...$account, 'class' => 'App\\Removed']]], '"App\\Removed" is not an existing class'];
        yield 'a class that is no longer an entity' => [['entities' => [[...$account, 'class' => self::INVALID . 'NotMarked']]], 'NotMarked has no #[Kinetis\\Orm\\Attributes\\Entity] attribute'];
        yield 'a duplicate entry' => [['entities' => [$account, $account]], 'is listed more than once'];
        yield 'an entry with an extra field' => [['entities' => [[...$account, 'schema' => 'x'], $category]], Account::class . ' does not match'];
        yield 'a renamed column' => [['entities' => [$account, $renamed]], ArticleCategory::class . ' does not match'];
        yield 'a changed type' => [['entities' => [$account, $retyped]], ArticleCategory::class . ' does not match'];
        yield 'a string recorded as a timestamp' => [['entities' => [$account, $timed]], ArticleCategory::class . ' does not match'];
        yield 'a timestamp recorded as a string' => [['entities' => [$untimed]], Event::class . ' does not match'];
        yield 'a string recorded as a date' => [['entities' => [$account, $dated]], ArticleCategory::class . ' does not match'];
        yield 'a date recorded as a string' => [['entities' => [$undated]], Booking::class . ' does not match'];
        yield 'a date recorded as a timestamp' => [['entities' => [$retimed]], Booking::class . ' does not match'];
        yield 'a date type name in another case' => [['entities' => [$miscased]], Booking::class . ' does not match'];
        yield 'a changed nullability' => [['entities' => [$account, $nullable]], ArticleCategory::class . ' does not match'];
        yield 'a changed identifier' => [['entities' => [$reidentified, $category]], Account::class . ' does not match'];
        yield 'a changed identifier generation' => [['entities' => [$account, $generated]], ArticleCategory::class . ' does not match'];
        yield 'a changed table' => [['entities' => [$account, $retabled]], ArticleCategory::class . ' does not match'];
        yield 'a connection the source no longer declares' => [['entities' => [$account, $rerouted]], ArticleCategory::class . ' does not match'];
        yield 'an entry without a connection field' => [['entities' => [$account, $unrouted]], ArticleCategory::class . ' does not match'];
        yield 'the default connection for a source that names one' => [['entities' => [$defaulted]], Metric::class . ' does not match'];
        yield 'a changed connection' => [['entities' => [$moved]], Metric::class . ' does not match'];
        yield 'an entry without a version field' => [['entities' => [$account, $unversioned]], ArticleCategory::class . ' does not match'];
        yield 'a version the source does not declare' => [['entities' => [$account, $versioned]], ArticleCategory::class . ' does not match'];
        yield 'a version the source declares left out' => [['entities' => [$dropped]], Invoice::class . ' does not match'];
        yield 'entries out of order' => [['entities' => [$category, $account]], ArticleCategory::class . ' does not match'];
        yield 'a changed relationship target' => [$graph($retargeted), Author::class . ' does not match'];
        yield 'a property without a target field' => [$graph($untargeted), Author::class . ' does not match'];
        yield 'a relationship recorded as a scalar' => [$graph($scalar), Author::class . ' does not match'];
        yield 'a relationship whose target has no entry' => [['entities' => [$author]], Author::class . '::$organization is not a usable #[BelongsTo] relationship: ' . Organization::class . ' is not an entity'];
        yield 'an entry without an inverses field' => [['entities' => [$account, $uninversed]], ArticleCategory::class . ' does not match'];
        yield 'an inverse relationship the source does not declare' => [['entities' => [$account, $invented]], ArticleCategory::class . ' does not match'];
        yield 'an inverse relationship the source declares left out' => [$graph($unlisted), Author::class . ' does not match'];
        yield 'inverse relationships out of order' => [$graph($reordered), Author::class . ' does not match'];
        yield 'a changed inverse kind' => [$graph($rekinded), Author::class . ' does not match'];
        yield 'a changed inverse target' => [$graph($reinversed), Author::class . ' does not match'];
        yield 'a changed mappedBy' => [$graph($remapped), Author::class . ' does not match'];
        yield 'a changed inverse nullability' => [$graph($renulled), Author::class . ' does not match'];
        yield 'an inverse relationship without a nullable field' => [$graph($unnulled), Author::class . ' does not match'];
        yield 'an inverse relationship with an extra field' => [$graph($extended), Author::class . ' does not match'];
        yield 'an entry without a joins field' => [['entities' => [$account, $unjoined]], ArticleCategory::class . ' does not match'];
        yield 'a changed join table' => [$parcels($rejoined), Parcel::class . ' does not match'];
        yield 'a changed join column' => [$parcels($recolumned), Parcel::class . ' does not match'];
        yield 'an inverse join side recorded as owning' => [['entities' => [$parcel, $reowned, $stamp]], Ribbon::class . ' does not match'];
        yield 'an inverse relationship whose target has no entry' => [
            ['entities' => [$author, $charter, $comment, $organization, $post]],
            Author::class . '::$profile is not a usable #[HasOne] or #[HasMany] relationship: ' . Profile::class . ' is not an entity',
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('unusableMetadata')]
    public function test_malformed_or_stale_metadata_is_refused(array $data, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        MetadataRegistry::fromArray($data);
    }
}
