<?php

declare(strict_types=1);

/**
 * Entity declarations MetadataRegistry must refuse, one per class. Loaded
 * with require_once by MetadataRegistryTest rather than autoloaded, since
 * PSR-4 maps one class per file.
 */

namespace Kinetis\Orm\Tests\Fixtures\Invalid;

use Countable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\Version;
use Kinetis\Orm\Tests\Fixtures\ArticleCategory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Traversable;

final class NotMarked
{
    public int $id;
}

#[Entity]
abstract class AbstractEntity
{
    public int $id;
}

#[Entity]
enum EnumEntity: string
{
    case One = 'one';
}

#[Entity]
final readonly class ReadonlyEntity
{
    public int $id;
}

class ParentRecord
{
}

#[Entity]
final class ChildEntity extends ParentRecord
{
    public int $id;
}

#[Entity]
final class ReadonlyProperty
{
    public int $id;

    public readonly string $name;
}

#[Entity]
final class HookedProperty
{
    public int $id;

    public string $name {
        set {
            $this->name = strtoupper($value);
        }
    }
}

#[Entity]
final class VirtualProperty
{
    public int $id;

    public string $label {
        get => 'virtual';
    }
}

#[Entity]
final class UntypedProperty
{
    public int $id;

    /** @var mixed */
    public $name;
}

#[Entity]
final class UnionProperty
{
    public int $id;

    public int|string $code;
}

#[Entity]
final class IntersectionProperty
{
    public int $id;

    /** @var Countable&Traversable<int, int> */
    public Countable&Traversable $items;
}

#[Entity]
final class ArrayProperty
{
    public int $id;

    /** @var list<string> */
    public array $tags;
}

#[Entity]
final class MutableDateProperty
{
    public int $id;

    public DateTime $publishedAt;
}

#[Entity]
final class DateInterfaceProperty
{
    public int $id;

    public DateTimeInterface $publishedAt;
}

final class LocalDate extends DateTimeImmutable
{
}

#[Entity]
final class DateSubclassProperty
{
    public int $id;

    public ?LocalDate $publishedAt;
}

#[Entity]
final class TimestampIdentifier
{
    public DateTimeImmutable $id;
}

#[Entity]
final class TimestampVersion
{
    public int $id;

    #[Version]
    public DateTimeImmutable $version;
}

#[Entity]
final class TimestampRelationship
{
    public int $id;

    #[BelongsTo]
    public DateTimeImmutable $publishedAt;
}

#[Entity]
final class MixedProperty
{
    public int $id;

    public mixed $data;
}

enum Suit
{
    case Hearts;
}

#[Entity]
final class UnitEnumProperty
{
    public int $id;

    public Suit $suit;
}

#[Entity]
final class MissingIdentifier
{
    public string $name;
}

#[Entity]
final class TwoIdentifiers
{
    #[Id]
    public int $first;

    #[Id]
    public int $second;
}

#[Entity]
final class FloatIdentifier
{
    public float $id;
}

#[Entity]
final class EnumIdentifier
{
    public ArticleStatus $id;
}

#[Entity]
final class GeneratedStringIdentifier
{
    #[Id(generated: true)]
    public ?string $id;
}

#[Entity]
final class GeneratedNonNullableIdentifier
{
    #[Id(generated: true)]
    public int $id;
}

#[Entity]
final class NullableVersion
{
    public int $id;

    #[Version]
    public ?int $version;
}

#[Entity]
final class StringVersion
{
    public int $id;

    #[Version]
    public string $version;
}

#[Entity]
final class EnumVersion
{
    public int $id;

    #[Version]
    public Priority $version;
}

#[Entity]
final class TwoVersions
{
    public int $id;

    #[Version]
    public int $first;

    #[Version]
    public int $second;
}

#[Entity]
final class IdentifierVersion
{
    #[Id]
    #[Version]
    public int $id;
}

#[Entity(table: 'article-list')]
final class InvalidTable
{
    public int $id;
}

#[Entity(table: '')]
final class EmptyTable
{
    public int $id;
}

#[Entity(table: 'reporting.')]
final class TrailingDotTable
{
    public int $id;
}

#[Entity]
final class InvalidColumn
{
    public int $id;

    #[Column(name: 'first name')]
    public string $name;
}

#[Entity]
final class EmptyColumn
{
    public int $id;

    #[Column(name: '')]
    public string $name;
}

#[Entity]
final class DuplicateColumn
{
    public int $id;

    #[Column(name: 'ID')]
    public int $legacyId;
}

#[Entity]
final class RelationshipScalar
{
    public int $id;

    #[BelongsTo]
    public int $categoryId;
}

#[Entity]
final class RelationshipWithColumn
{
    public int $id;

    #[BelongsTo]
    #[Column(name: 'category')]
    public ArticleCategory $category;
}

#[Entity]
final class RelationshipIdentifier
{
    public int $id;

    #[Id]
    #[BelongsTo]
    public ArticleCategory $category;
}

#[Entity]
final class RelationshipVersion
{
    public int $id;

    #[Version]
    #[BelongsTo]
    public ArticleCategory $category;
}

#[Entity]
final class RelationshipDefault
{
    public int $id;

    #[BelongsTo]
    public ?ArticleCategory $category = null;
}

#[Entity]
final class RelationshipInvalidColumn
{
    public int $id;

    #[BelongsTo(column: 'category id')]
    public ArticleCategory $category;
}

#[Entity]
final class RelationshipDuplicateColumn
{
    public int $id;

    public int $categoryId;

    #[BelongsTo]
    public ArticleCategory $category;
}

#[Entity]
final class RelationshipUnknownTarget
{
    public int $id;

    #[BelongsTo]
    public Document $document;
}

#[Entity]
final class RelationshipIdentifierByName
{
    #[BelongsTo]
    public ArticleCategory $id;
}

#[Entity]
final class InverseDefaultList
{
    public int $id;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public array $children = [];
}

#[Entity]
final class InverseDefaultNull
{
    public int $id;

    #[HasOne(mappedBy: 'parent')]
    public ?self $child = null;
}

#[Entity]
final class HasOneScalar
{
    public int $id;

    #[HasOne(mappedBy: 'parent')]
    public ?int $childId;
}

#[Entity]
final class HasOneArray
{
    public int $id;

    /** @var list<self> */
    #[HasOne(mappedBy: 'parent')]
    public array $children;
}

#[Entity]
final class HasManyNullable
{
    public int $id;

    /** @var list<self>|null */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public ?array $children;
}

#[Entity]
final class HasManyObject
{
    public int $id;

    #[HasMany(target: ArticleCategory::class, mappedBy: 'parent')]
    public ArticleCategory $category;
}

#[Entity]
final class InverseBoth
{
    public int $id;

    #[HasOne(mappedBy: 'parent')]
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public ?self $child;
}

#[Entity]
final class InverseBelongsTo
{
    public int $id;

    #[BelongsTo]
    #[HasOne(mappedBy: 'parent')]
    public ?self $child;
}

#[Entity]
final class InverseWithColumn
{
    public int $id;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    #[Column(name: 'children')]
    public array $children;
}

#[Entity]
final class InverseIdentifier
{
    #[Id]
    #[HasOne(mappedBy: 'parent')]
    public ?self $twin;
}

#[Entity]
final class InverseVersion
{
    public int $id;

    /** @var list<self> */
    #[Version]
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public array $children;
}

#[Entity]
final class InverseReadonly
{
    public int $id;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public readonly array $children;
}

#[Entity]
final class HasManyUnknownTarget
{
    public int $id;

    /** @var list<Document> */
    #[HasMany(target: Document::class, mappedBy: 'owner')]
    public array $documents;
}

#[Entity]
final class HasOneUnknownTarget
{
    public int $id;

    #[HasOne(mappedBy: 'owner')]
    public ?Document $document;
}

#[Entity]
final class InverseUnknownMappedBy
{
    public int $id;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public array $children;
}

#[Entity]
final class InverseMappedByInverse
{
    public int $id;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'children')]
    public array $children;
}

#[Entity]
final class InverseScalarMappedBy
{
    public int $id;

    public ?int $parent;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public array $children;
}

#[Entity]
final class InverseChild
{
    public int $id;

    #[BelongsTo]
    public ArticleCategory $category;
}

#[Entity]
final class InverseWrongDirection
{
    public int $id;

    /** @var list<InverseChild> */
    #[HasMany(target: InverseChild::class, mappedBy: 'category')]
    public array $children;
}

#[Entity]
final class InverseWrongSelf
{
    public int $id;

    #[BelongsTo]
    public ArticleCategory $category;

    #[HasOne(mappedBy: 'category')]
    public ?self $twin;
}

interface EntityInterface
{
}
