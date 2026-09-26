<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Flush\Reference;
use Kinetis\Orm\Metadata\MetadataRegistry;
use ReflectionClass;
use ReflectionProperty;

/**
 * @internal One entity's runtime mapping, built once by OrmFactory from its
 *           MetadataRegistry entry. It keeps the reflection that allocates,
 *           reads and writes the entity, never an entity or request state.
 *
 * Values follow the driver-value domain of Kinetis\QueryBuilder\RowMapper:
 * a string is a string; an int is an int or its canonical decimal string; a
 * float is a finite int, float or numeric string; a bool is a bool, 0, 1,
 * "0" or "1"; a backed enum is a case or a backing value under its backing
 * type's rule; a timestamp is a DateTimeImmutable in any zone or a UTC
 * "Y-m-d H:i:s" string with up to six fraction digits, in UTC years 0001 to
 * 9999, and a string loads as a DateTimeImmutable in UTC; a date is a Date
 * or its exact "Y-m-d" string, and a string loads as a Date; null only
 * where the property type allows it.
 *
 * A database value is a converted value with a backed enum replaced by its
 * backing value, a DateTimeImmutable by its UTC "Y-m-d H:i:s.u" string and
 * a Date by its "Y-m-d" string. Predicate parameters, cursors, snapshots
 * and written rows all use it, so a loaded value and the same value read
 * back from the entity compare identical, and one instant has one database
 * value in every zone.
 *
 * A #[BelongsTo] property is one of the mapped properties, over its
 * foreign-key column. Its converted and database value is the target's
 * identifier, and instantiate() leaves the property uninitialized.
 *
 * An inverse relationship and a #[ManyToMany] join collection map no
 * column, so they are kept apart from the mapped properties: conversion,
 * instantiate(), extract() and every column operation leave them out, and
 * only the relationship methods reach them.
 *
 * @template T of object
 * @phpstan-import-type EntityMapping from MetadataRegistry
 * @phpstan-import-type InverseMapping from MetadataRegistry
 * @phpstan-import-type JoinMapping from MetadataRegistry
 * @phpstan-import-type PropertyMapping from MetadataRegistry
 * @psalm-import-type EntityMapping from MetadataRegistry
 * @psalm-import-type InverseMapping from MetadataRegistry
 * @psalm-import-type JoinMapping from MetadataRegistry
 * @psalm-import-type PropertyMapping from MetadataRegistry
 */
final class EntityPlan
{
    /**
     * A timestamp's UTC string: the pattern fixes the shape and the parse
     * checks the calendar. PHP parses year 0000 without a warning, so the
     * pattern refuses it.
     */
    private const string TIMESTAMP = '/^(?!0000)\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D';

    /** @var class-string<T> */
    public readonly string $class;

    public readonly string $table;

    /** The identifier property's name. */
    public readonly string $id;

    /** Whether the database generates the identifier when the entity is inserted. */
    public readonly bool $generated;

    /** The version property's name, or null when the entity is not versioned. */
    public readonly ?string $version;

    /** @var ReflectionClass<T> */
    private readonly ReflectionClass $reflection;

    /** @var array<string, PropertyMapping> */
    private readonly array $properties;

    /** @var array<string, ReflectionProperty> */
    private readonly array $accessors;

    /** @var array<string, InverseMapping> */
    private readonly array $inverses;

    /** @var array<string, ReflectionProperty> */
    private readonly array $inverseAccessors;

    /** @var array<string, JoinMapping> */
    private readonly array $joins;

    /** @var array<string, ReflectionProperty> */
    private readonly array $joinAccessors;

    /**
     * @param EntityMapping $mapping
     */
    public function __construct(array $mapping)
    {
        /** @var class-string<T> $class */
        $class = $mapping['class'];
        $this->class = $class;
        $this->table = $mapping['table'];
        $this->id = $mapping['id'];
        $this->generated = $mapping['generated'];
        $this->version = $mapping['version'];
        $this->reflection = new ReflectionClass($class);

        $properties = [];
        $accessors = [];

        foreach ($mapping['properties'] as $property) {
            $properties[$property['name']] = $property;
            $accessors[$property['name']] = $this->reflection->getProperty($property['name']);
        }

        $this->properties = $properties;
        $this->accessors = $accessors;

        $inverses = [];
        $inverseAccessors = [];

        foreach ($mapping['inverses'] as $inverse) {
            $inverses[$inverse['name']] = $inverse;
            $inverseAccessors[$inverse['name']] = $this->reflection->getProperty($inverse['name']);
        }

        $this->inverses = $inverses;
        $this->inverseAccessors = $inverseAccessors;

        $joins = [];
        $joinAccessors = [];

        foreach ($mapping['joins'] as $join) {
            $joins[$join['name']] = $join;
            $joinAccessors[$join['name']] = $this->reflection->getProperty($join['name']);
        }

        $this->joins = $joins;
        $this->joinAccessors = $joinAccessors;
    }

    /**
     * @return list<string> every mapped column, in declaration order
     */
    public function columns(): array
    {
        return array_column($this->properties, 'column');
    }

    /** @throws MappingException for a property this entity does not map, or one that maps no column */
    public function column(string $property): string
    {
        return $this->property($property)['column'];
    }

    /**
     * @return class-string the entity the relationship $property references, or the entity an inverse or join relationship holds
     * @throws MappingException for a property this entity does not map, or one that is not a relationship
     */
    public function target(string $property): string
    {
        return $this->inverses[$property]['target']
            ?? $this->joins[$property]['target']
            ?? $this->property($property)['target']
            ?? throw MappingException::notARelation($this->class, $property);
    }

    /**
     * @return InverseMapping|null the inverse relationship named $property, or null for any other name
     */
    public function inverse(string $property): ?array
    {
        return $this->inverses[$property] ?? null;
    }

    /**
     * @return JoinMapping|null the #[ManyToMany] relationship named $property, or null for any other name
     */
    public function join(string $property): ?array
    {
        return $this->joins[$property] ?? null;
    }

    /**
     * Every owning #[ManyToMany] relationship, in declaration order: the
     * join collections flush() diffs and writes. An inverse one is left out,
     * because the owning side writes every row of its table.
     *
     * @return array<string, JoinMapping>
     */
    public function owningJoins(): array
    {
        return array_filter($this->joins, static fn (array $join): bool => $join['mappedBy'] === null);
    }

    /**
     * Every #[BelongsTo] property, in declaration order: the entity class it
     * references, and whether its foreign-key column admits null, which is
     * what a reference loop can be broken through.
     *
     * @return array<string, array{class-string, bool}>
     */
    public function relations(): array
    {
        $relations = [];

        foreach ($this->properties as $name => $property) {
            $target = $property['target'];

            if ($target !== null) {
                $relations[$name] = [$target, $property['nullable']];
            }
        }

        return $relations;
    }

    /**
     * Every owned inverse relationship, in declaration order: the aggregate
     * ownership edges flush() discovers, removes and reconciles along.
     *
     * @return array<string, InverseMapping>
     */
    public function owned(): array
    {
        return array_filter($this->inverses, static fn (array $inverse): bool => $inverse['owned']);
    }

    /**
     * A predicate value for $property, admitted and converted like a loaded
     * value, as a database value. A relationship admits its target's
     * identifier, not an entity.
     *
     * @throws MappingException
     */
    public function parameter(string $property, mixed $value): null|bool|int|float|string
    {
        return self::databaseValue($this->convert($this->property($property), $value));
    }

    /**
     * A page cursor for $property. A timestamp or date cursor is admitted
     * like a predicate value and becomes its database value, so a malformed
     * one never reaches SQL and a timestamp offset never reaches a server
     * that would shift it through its session time zone. Any other cursor,
     * and null, pass as given.
     *
     * @throws MappingException
     */
    public function cursor(string $property, ?string $cursor): ?string
    {
        if ($cursor === null || !in_array($this->property($property)['type'], ['timestamp', 'date'], true)) {
            return $cursor;
        }

        /** @var string a non-null timestamp's or date's database value */
        return $this->parameter($property, $cursor);
    }

    /**
     * A caller's identifier, converted to the key the identity map holds.
     *
     * @throws MappingException
     */
    public function identifier(int|string $id): int|string
    {
        /** @var int|string */
        return $this->convert($this->properties[$this->id], $id);
    }

    /**
     * Every mapped column of $row, converted before anything is allocated:
     * the identifier first, then each property. Extra columns are ignored.
     *
     * @param array<string, mixed> $row
     * @return array{int|string, array<string, mixed>} the identifier and the value of every property
     * @throws MappingException
     */
    public function convertRow(array $row): array
    {
        $identifier = $this->properties[$this->id];
        $id = $this->convert($identifier, $this->read($row, $identifier));

        if ($id === null) {
            throw MappingException::nullIdentifier($this->class);
        }

        /** @var int|string $id */
        $values = [];

        foreach ($this->properties as $name => $property) {
            $values[$name] = $name === $this->id ? $id : $this->convert($property, $this->read($row, $property));
        }

        return [$id, $values];
    }

    /**
     * Allocates the entity without its constructor and writes every
     * column-mapped property directly but a relationship, which stays
     * uninitialized until it is eagerly loaded, as every inverse
     * relationship does. No hook, setter or magic method runs: hooked
     * properties are refused when the metadata is built.
     *
     * @param array<string, mixed> $values convertRow()'s values
     * @return T
     */
    public function instantiate(array $values): object
    {
        $entity = $this->reflection->newInstanceWithoutConstructor();

        foreach ($this->accessors as $name => $accessor) {
            if ($this->properties[$name]['target'] === null) {
                $accessor->setValue($entity, $values[$name]);
            }
        }

        return $entity;
    }

    /**
     * convertRow()'s values as database values: the snapshot a managed
     * entity's later values are compared against.
     *
     * @param array<string, mixed> $values
     * @return array<string, null|bool|int|float|string>
     */
    public function snapshot(array $values): array
    {
        return array_map(self::databaseValue(...), $values);
    }

    /**
     * The database value of every mapped property $entity holds now, each
     * admitted like a loaded value. A relationship holding an entity takes
     * that entity's identifier from $identify, or its Reference when an
     * insert of this flush generates it. An uninitialized relationship of a
     * managed entity keeps the foreign key of its $snapshot, so a
     * relationship that was never loaded is never written.
     *
     * @param array<string, null|bool|int|float|string>|null $snapshot the managed entity's snapshot, or null
     * @param Closure(object): (int|string|Reference|null) $identify a target's identifier, a Reference to the insert that generates it, or null when the manager does not hold the target
     * @return array<string, null|bool|int|float|string|Reference> property => database value
     * @throws InvalidEntityStateException for an uninitialized property or a relationship target the manager does not hold
     * @throws MappingException for a value its property does not admit, such as a non-finite float
     */
    public function extract(object $entity, ?array $snapshot, Closure $identify): array
    {
        $values = [];

        foreach ($this->accessors as $name => $accessor) {
            $property = $this->properties[$name];

            if (!$accessor->isInitialized($entity)) {
                $values[$name] = $property['target'] !== null && $snapshot !== null
                    ? $snapshot[$name]
                    : throw InvalidEntityStateException::uninitialized($this->class, $name);

                continue;
            }

            $value = $accessor->getValue($entity);

            if ($property['target'] !== null && is_object($value)) {
                $value = $identify($value) ?? throw InvalidEntityStateException::relationTargetNotHeld($this->class, $name);

                if ($value instanceof Reference) {
                    $values[$name] = $value;

                    continue;
                }
            }

            $values[$name] = self::databaseValue($this->convert($property, $value));
        }

        return $values;
    }

    /** Whether $property, a mapped property or a relationship of either kind, holds a value. */
    public function initialized(object $entity, string $property): bool
    {
        return $this->accessor($property)->isInitialized($entity);
    }

    /**
     * What an initialized relationship holds: a target or null, or the array
     * of a #[HasMany] or #[ManyToMany].
     *
     * @return object|array<array-key, mixed>|null
     */
    public function related(object $entity, string $property): object|array|null
    {
        /** @var object|array<array-key, mixed>|null a relationship property is typed with its target class, or array */
        return $this->accessor($property)->getValue($entity);
    }

    /**
     * @param array<string, null|bool|int|float|string> $values property => database value
     * @return array<string, null|bool|int|float|string> column => database value
     */
    public function row(array $values): array
    {
        $row = [];

        foreach ($values as $name => $value) {
            $row[$this->properties[$name]['column']] = $value;
        }

        return $row;
    }

    /**
     * The key an insert reported for the generated identifier, admitted
     * like a loaded int.
     *
     * @throws InvalidEntityStateException for null or any value an int property does not admit
     */
    public function generatedIdentifier(int|string|null $key): int
    {
        return ($key === null ? null : self::int($key))
            ?? throw InvalidEntityStateException::invalidGeneratedIdentifier($this->class);
    }

    /**
     * Writes a value the manager owns into its property: a generated
     * identifier or a version the flush wrote, or an eagerly loaded
     * relationship's target, null or list of targets.
     *
     * @param int|object|list<object>|null $value
     */
    public function assign(object $entity, string $property, int|object|array|null $value): void
    {
        $this->accessor($property)->setValue($entity, $value);
    }

    private function accessor(string $property): ReflectionProperty
    {
        return $this->accessors[$property] ?? $this->inverseAccessors[$property] ?? $this->joinAccessors[$property];
    }

    /**
     * @return PropertyMapping
     */
    private function property(string $property): array
    {
        return $this->properties[$property] ?? throw (isset($this->inverses[$property]) || isset($this->joins[$property])
            ? MappingException::notAColumn($this->class, $property)
            : MappingException::unknownProperty($this->class, $property));
    }

    /**
     * @param array<string, mixed> $row
     * @param PropertyMapping $property
     */
    private function read(array $row, array $property): mixed
    {
        if (!array_key_exists($property['column'], $row)) {
            throw MappingException::missingColumn($this->class, $property['name'], $property['column']);
        }

        return $row[$property['column']];
    }

    /**
     * @param PropertyMapping $property
     */
    private function convert(array $property, mixed $value): mixed
    {
        $enum = $property['enum'];

        if ($value === null) {
            return $property['nullable']
                ? null
                : throw MappingException::invalidValue(
                    $this->class,
                    $property['name'],
                    $this->table,
                    $property['column'],
                    self::expected($property),
                    $value,
                );
        }

        if ($enum !== null && $value instanceof $enum) {
            return $value;
        }

        $converted = match ($property['type']) {
            'string' => is_string($value) ? $value : null,
            'int' => self::int($value),
            'float' => self::float($value),
            'bool' => self::bool($value),
            'timestamp' => self::timestamp($value),
            'date' => self::date($value),
        };

        if ($converted === null) {
            throw MappingException::invalidValue(
                $this->class,
                $property['name'],
                $this->table,
                $property['column'],
                self::expected($property),
                $value,
            );
        }

        if ($enum === null) {
            return $converted;
        }

        /** @var int|string $converted a backed enum's type is its backing type */
        return $enum::tryFrom($converted)
            ?? throw MappingException::unknownEnumCase(
                $this->class,
                $property['name'],
                $this->table,
                $property['column'],
                $enum,
                $value,
            );
    }

    private static function databaseValue(mixed $converted): null|bool|int|float|string
    {
        /** @var null|bool|int|float|string|BackedEnum|DateTimeImmutable|Date $converted */
        return match (true) {
            $converted instanceof BackedEnum => $converted->value,
            $converted instanceof DateTimeImmutable => self::utc($converted),
            $converted instanceof Date => (string) $converted,
            default => $converted,
        };
    }

    /**
     * The canonical spelling alone: a leading "-" but no "+", whitespace,
     * leading zero, fraction or exponent, and nothing outside PHP's int
     * range.
     */
    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && (string) (int) $value === $value ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        $float = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;

        return $float !== null && is_finite($float) ? $float : null;
    }

    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1' => true,
            $value === 0, $value === '0' => false,
            default => null,
        };
    }

    /**
     * An instance is admitted when its UTC value is inside the string domain,
     * which bounds the year to 0001-9999. A string is parsed as UTC with a
     * zero-padded fraction, since PostgreSQL omits a zero fraction and PHP's
     * "u" needs one digit or more; any parse warning, such as for a day,
     * hour or second that does not exist, refuses it.
     */
    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return preg_match(self::TIMESTAMP, self::utc($value)) === 1 ? $value : null;
        }

        if (!is_string($value) || preg_match(self::TIMESTAMP, $value) !== 1) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            str_pad(strlen($value) === 19 ? "{$value}." : $value, 26, '0'),
            new DateTimeZone('UTC'),
        );

        return $timestamp !== false && DateTimeImmutable::getLastErrors() === false ? $timestamp : null;
    }

    /** Date::fromString() alone decides which strings are dates. */
    private static function date(mixed $value): ?Date
    {
        if ($value instanceof Date) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return Date::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /**
     * @param PropertyMapping $property
     */
    private static function expected(array $property): string
    {
        $shape = match ($property['type']) {
            'string' => 'a string',
            'int' => 'an int or its canonical decimal string',
            'float' => 'a finite int, float or numeric string',
            'bool' => 'a bool, 0, 1, "0" or "1"',
            'timestamp' => 'a DateTimeImmutable, or a UTC "Y-m-d H:i:s" string with up to six fraction digits and no offset, in UTC years 0001 to 9999',
            'date' => 'a ' . Date::class . ', or a "Y-m-d" string naming a day that exists in years 0001 to 9999',
        };

        if ($property['enum'] !== null) {
            $shape = "a {$property['enum']} case or its backing value as {$shape}";
        }

        return $property['nullable'] ? "{$shape}, or null" : $shape;
    }
}
