<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use BackedEnum;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use ReflectionClass;
use ReflectionProperty;

/**
 * @internal One entity's runtime mapping, built once by OrmFactory from its
 *           MetadataRegistry entry. It keeps the reflection that allocates
 *           and writes the entity, never an entity or request state.
 *
 * Values follow the driver-value domain of Kinetis\QueryBuilder\RowMapper:
 * a string is a string; an int is an int or its canonical decimal string; a
 * float is a finite int, float or numeric string; a bool is a bool, 0, 1,
 * "0" or "1"; a backed enum is a case or a backing value under its backing
 * type's rule; null only where the property type allows it.
 *
 * @template T of object
 * @phpstan-import-type EntityMapping from MetadataRegistry
 * @phpstan-import-type PropertyMapping from MetadataRegistry
 * @psalm-import-type EntityMapping from MetadataRegistry
 * @psalm-import-type PropertyMapping from MetadataRegistry
 */
final class EntityPlan
{
    /** @var class-string<T> */
    public readonly string $class;

    public readonly string $table;

    /** The identifier property's name. */
    public readonly string $id;

    /** @var ReflectionClass<T> */
    private readonly ReflectionClass $reflection;

    /** @var array<string, PropertyMapping> */
    private readonly array $properties;

    /** @var array<string, ReflectionProperty> */
    private readonly array $writers;

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
        $this->reflection = new ReflectionClass($class);

        $properties = [];
        $writers = [];

        foreach ($mapping['properties'] as $property) {
            $properties[$property['name']] = $property;
            $writers[$property['name']] = $this->reflection->getProperty($property['name']);
        }

        $this->properties = $properties;
        $this->writers = $writers;
    }

    /**
     * @return list<string> every mapped column, in declaration order
     */
    public function columns(): array
    {
        return array_column($this->properties, 'column');
    }

    /** @throws MappingException for a property this entity does not map */
    public function column(string $property): string
    {
        return $this->property($property)['column'];
    }

    /**
     * A predicate value for $property, admitted and converted like a loaded
     * value; a backed enum is bound as its backing value.
     *
     * @throws MappingException
     */
    public function parameter(string $property, mixed $value): null|bool|int|float|string
    {
        $converted = $this->convert($this->property($property), $value);

        /** @var null|bool|int|float|string|BackedEnum $converted */
        return $converted instanceof BackedEnum ? $converted->value : $converted;
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
     * declared property directly. No hook, setter or magic method runs:
     * hooked properties are refused when the metadata is built.
     *
     * @param array<string, mixed> $values convertRow()'s values
     * @return T
     */
    public function instantiate(array $values): object
    {
        $entity = $this->reflection->newInstanceWithoutConstructor();

        foreach ($this->writers as $name => $writer) {
            $writer->setValue($entity, $values[$name]);
        }

        return $entity;
    }

    /**
     * @return PropertyMapping
     */
    private function property(string $property): array
    {
        return $this->properties[$property] ?? throw MappingException::unknownProperty($this->class, $property);
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
                : throw MappingException::invalidValue($this->class, $property['name'], self::expected($property), $value);
        }

        if ($enum !== null && $value instanceof $enum) {
            return $value;
        }

        $converted = match ($property['type']) {
            'string' => is_string($value) ? $value : null,
            'int' => self::int($value),
            'float' => self::float($value),
            'bool' => self::bool($value),
        };

        if ($converted === null) {
            throw MappingException::invalidValue($this->class, $property['name'], self::expected($property), $value);
        }

        if ($enum === null) {
            return $converted;
        }

        /** @var int|string $converted a backed enum's type is its backing type */
        return $enum::tryFrom($converted)
            ?? throw MappingException::unknownEnumCase($this->class, $property['name'], $enum, $value);
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
     * @param PropertyMapping $property
     */
    private static function expected(array $property): string
    {
        $shape = match ($property['type']) {
            'string' => 'a string',
            'int' => 'an int or its canonical decimal string',
            'float' => 'a finite int, float or numeric string',
            'bool' => 'a bool, 0, 1, "0" or "1"',
        };

        if ($property['enum'] !== null) {
            $shape = "a {$property['enum']} case or its backing value as {$shape}";
        }

        return $property['nullable'] ? "{$shape}, or null" : $shape;
    }
}
