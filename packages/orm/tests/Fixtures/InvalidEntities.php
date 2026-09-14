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
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
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

interface EntityInterface
{
}
