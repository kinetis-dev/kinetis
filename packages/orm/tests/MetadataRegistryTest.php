<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use ArrayIterator;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\Tests\Fixtures\Account;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleCategory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Author;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Edition;
use Kinetis\Orm\Tests\Fixtures\Invoice;
use Kinetis\Orm\Tests\Fixtures\Organization;
use Kinetis\Orm\Tests\Fixtures\Post;
use Kinetis\Orm\Tests\Fixtures\Priority;
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
                'id' => 'id',
                'generated' => false,
                'version' => null,
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'displayName', 'column' => 'display_name', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
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
                'id' => 'uuid',
                'generated' => false,
                'version' => null,
                'properties' => [
                    ['name' => 'uuid', 'column' => 'account_uuid', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => null],
                    ['name' => 'email', 'column' => 'email_address', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
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
                'id' => 'id',
                'generated' => true,
                'version' => null,
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => true, 'enum' => null, 'target' => null],
                    ['name' => 'subject', 'column' => 'subject', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
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
                'id' => 'id',
                'generated' => false,
                'version' => 'version',
                'properties' => [
                    ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'status', 'column' => 'status', 'type' => 'string', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'version', 'column' => 'row_version', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                    ['name' => 'total', 'column' => 'total', 'type' => 'int', 'nullable' => false, 'enum' => null, 'target' => null],
                ],
            ]]],
            $data,
        );
        self::assertSame($data, MetadataRegistry::fromArray($data)->toArray());
    }

    public function test_a_relationship_maps_its_foreign_key_as_the_target_identifier_type_and_round_trips(): void
    {
        $data = MetadataRegistry::fromClasses([Post::class, Topic::class, Organization::class, Author::class])->toArray();
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
        yield 'a DateTimeImmutable' => [self::INVALID . 'DateProperty', 'DateProperty::$publishedAt cannot be mapped: it declares DateTimeImmutable'];
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
    }

    #[DataProvider('unmappableClasses')]
    public function test_an_unmappable_class_is_refused(mixed $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        MetadataRegistry::fromClasses([ArticleCategory::class, $class]);
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
        $reidentified = $account;
        $reidentified['id'] = 'id';
        $generated = $category;
        $generated['generated'] = true;
        $retabled = $category;
        $retabled['table'] = 'categories';
        $unversioned = $category;
        unset($unversioned['version']);
        $versioned = $category;
        $versioned['version'] = 'displayName';
        $invoice = MetadataRegistry::fromClasses([Invoice::class])->toArray()['entities'][0];
        $dropped = $invoice;
        $dropped['version'] = null;
        [$author, $organization] = MetadataRegistry::fromClasses([Author::class, Organization::class])->toArray()['entities'];
        $retargeted = $author;
        $retargeted['properties'][2]['target'] = Account::class;
        $untargeted = $author;
        unset($untargeted['properties'][2]['target']);
        $scalar = $author;
        $scalar['properties'][2]['target'] = null;

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
        yield 'a changed nullability' => [['entities' => [$account, $nullable]], ArticleCategory::class . ' does not match'];
        yield 'a changed identifier' => [['entities' => [$reidentified, $category]], Account::class . ' does not match'];
        yield 'a changed identifier generation' => [['entities' => [$account, $generated]], ArticleCategory::class . ' does not match'];
        yield 'a changed table' => [['entities' => [$account, $retabled]], ArticleCategory::class . ' does not match'];
        yield 'an entry without a version field' => [['entities' => [$account, $unversioned]], ArticleCategory::class . ' does not match'];
        yield 'a version the source does not declare' => [['entities' => [$account, $versioned]], ArticleCategory::class . ' does not match'];
        yield 'a version the source declares left out' => [['entities' => [$dropped]], Invoice::class . ' does not match'];
        yield 'entries out of order' => [['entities' => [$category, $account]], ArticleCategory::class . ' does not match'];
        yield 'a changed relationship target' => [['entities' => [$retargeted, $organization]], Author::class . ' does not match'];
        yield 'a property without a target field' => [['entities' => [$untargeted, $organization]], Author::class . ' does not match'];
        yield 'a relationship recorded as a scalar' => [['entities' => [$scalar, $organization]], Author::class . ' does not match'];
        yield 'a relationship whose target has no entry' => [['entities' => [$author]], Author::class . '::$organization is not a usable #[BelongsTo] relationship: ' . Organization::class . ' is not an entity'];
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
