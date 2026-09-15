<?php

declare(strict_types=1);

/**
 * Entity declarations MetadataRegistry must refuse, one per class. Loaded
 * with require_once by MetadataRegistryTest rather than autoloaded, since
 * PSR-4 maps one class per file.
 */

namespace Kinetis\Orm\Tests\Fixtures\Invalid;

use Countable;
use DateTimeImmutable;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
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
final class DateProperty
{
    public int $id;

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

interface EntityInterface
{
}
