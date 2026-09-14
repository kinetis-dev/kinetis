<?php

declare(strict_types=1);

namespace Kinetis\Orm\Metadata;

use BackedEnum;
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\Version;
use Kinetis\Orm\Exception\MappingException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The mapping of an explicit set of entity classes, held as plain data:
 * class names, table and column names, scalar type names and flags.
 * toArray() is exactly what fromArray() accepts, so a build step can export
 * the mapping and a worker can load it without scanning a directory.
 *
 * Both constructors reflect each named class and validate it against its
 * current source, so a registry never describes a class as it no longer
 * is. Nothing is cached beyond the instance.
 *
 * @phpstan-type PropertyMapping array{name: string, column: string, type: 'string'|'int'|'float'|'bool', nullable: bool, enum: class-string<BackedEnum>|null}
 * @phpstan-type EntityMapping array{class: class-string, table: string, id: string, generated: bool, version: string|null, properties: list<PropertyMapping>}
 * @psalm-type PropertyMapping = array{name: string, column: string, type: 'string'|'int'|'float'|'bool', nullable: bool, enum: class-string<BackedEnum>|null}
 * @psalm-type EntityMapping = array{class: class-string, table: string, id: string, generated: bool, version: string|null, properties: list<PropertyMapping>}
 */
final readonly class MetadataRegistry
{
    private const string IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    private const array SCALAR_TYPES = ['string', 'int', 'float', 'bool'];

    /**
     * @param list<EntityMapping> $entities ordered by class name
     */
    private function __construct(private array $entities) {}

    /**
     * Maps every listed class, which must carry #[Entity]. The order of
     * $classes does not affect toArray().
     *
     * @param iterable<mixed> $classes
     * @throws MappingException
     */
    public static function fromClasses(iterable $classes): self
    {
        $entities = [];

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw MappingException::notAClass($class);
            }

            $mapping = self::entity(new ReflectionClass($class));

            if (isset($entities[$mapping['class']])) {
                throw MappingException::duplicateEntity($mapping['class']);
            }

            $entities[$mapping['class']] = $mapping;
        }

        ksort($entities, SORT_STRING);

        return new self(array_values($entities));
    }

    /**
     * Accepts only an array toArray() would write for the classes it names
     * as they are declared now: a missing or extra field, a wrong type, an
     * unknown class, a duplicate entry or a mapping the class no longer
     * produces is rejected. Only the named classes are reflected.
     *
     * @param array<array-key, mixed> $data
     * @throws MappingException
     */
    public static function fromArray(array $data): self
    {
        $entries = $data['entities'] ?? null;

        if (array_keys($data) !== ['entities'] || !is_array($entries) || !array_is_list($entries)) {
            throw MappingException::malformedMetadata('it must hold exactly one "entities" list');
        }

        $classes = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['class'] ?? null)) {
                throw MappingException::malformedMetadata('every entry needs a "class" string');
            }

            $classes[] = $entry['class'];
        }

        $registry = self::fromClasses($classes);

        foreach ($registry->entities as $i => $current) {
            if ($entries[$i] !== $current) {
                throw MappingException::staleMetadata($classes[$i]);
            }
        }

        return $registry;
    }

    /**
     * @return array{entities: list<EntityMapping>}
     */
    public function toArray(): array
    {
        return ['entities' => $this->entities];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return EntityMapping
     */
    private static function entity(ReflectionClass $class): array
    {
        $name = $class->getName();
        $attribute = $class->getAttributes(Entity::class)[0] ?? null;

        if ($attribute === null) {
            throw MappingException::notAnEntity($name);
        }

        $parent = $class->getParentClass();
        $reason = match (true) {
            $class->isEnum() => 'it is an enum',
            $class->isAbstract() => 'it is abstract',
            $class->isAnonymous() => 'it is an anonymous class',
            $class->isReadOnly() => 'it is a readonly class, and a loaded entity is mutable',
            $parent !== false => "it extends {$parent->getName()}, and an entity has no parent class",
            default => null,
        };

        if ($reason !== null) {
            throw MappingException::unsupportedEntity($name, $reason);
        }

        $table = $attribute->newInstance()->table ?? self::snakeCase($class->getShortName());

        if (preg_match('/^' . self::IDENTIFIER . '(\.' . self::IDENTIFIER . ')*$/D', $table) !== 1) {
            throw MappingException::invalidTable($name, $table);
        }

        /** @var array<string, PropertyMapping> $properties */
        $properties = [];
        $columns = [];
        $ids = [];
        $versions = [];
        $generated = false;

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $mapping = self::property($name, $property);
            $key = strtolower($mapping['column']);

            if (isset($columns[$key])) {
                throw MappingException::duplicateColumn($name, $columns[$key], $mapping['name'], $mapping['column']);
            }

            $columns[$key] = $mapping['name'];
            $properties[$mapping['name']] = $mapping;
            $marker = $property->getAttributes(Id::class)[0] ?? null;

            if ($marker !== null) {
                $ids[] = $mapping['name'];
                $generated = $marker->newInstance()->generated;
            }

            if ($property->getAttributes(Version::class) !== []) {
                $versions[] = $mapping['name'];
            }
        }

        $id = match (count($ids)) {
            0 => isset($properties['id'])
                ? 'id'
                : throw MappingException::identifier($name, 'no property carries #[Id] and none is named "id"'),
            1 => $ids[0],
            default => throw MappingException::identifier($name, 'more than one property carries #[Id]: ' . implode(', ', $ids)),
        };

        if ($properties[$id]['enum'] !== null || !in_array($properties[$id]['type'], ['int', 'string'], true)) {
            throw MappingException::identifier($name, "the identifier property \"{$id}\" must be typed int or string");
        }

        if ($generated && ($properties[$id]['type'] !== 'int' || !$properties[$id]['nullable'])) {
            throw MappingException::identifier($name, "the generated identifier property \"{$id}\" must be typed ?int");
        }

        $version = match (count($versions)) {
            0 => null,
            1 => $versions[0],
            default => throw MappingException::version($name, 'more than one property carries #[Version]: ' . implode(', ', $versions)),
        };

        if ($version === $id) {
            throw MappingException::version($name, "the identifier property \"{$id}\" cannot also be the version");
        }

        $mapping = $version === null ? null : $properties[$version];

        if ($mapping !== null && ($mapping['type'] !== 'int' || $mapping['nullable'] || $mapping['enum'] !== null)) {
            throw MappingException::version($name, "the version property \"{$version}\" must be typed int");
        }

        return [
            'class' => $name,
            'table' => $table,
            'id' => $id,
            'generated' => $generated,
            'version' => $version,
            'properties' => array_values($properties),
        ];
    }

    /**
     * @return PropertyMapping
     */
    private static function property(string $class, ReflectionProperty $property): array
    {
        $name = $property->getName();
        $type = $property->getType();

        if ($property->isReadOnly()) {
            throw MappingException::unsupportedProperty($class, $name, 'is readonly');
        }

        // Writing a hooked property through reflection runs its set hook,
        // application code this mapper never invokes.
        if ($property->hasHooks()) {
            throw MappingException::unsupportedProperty($class, $name, 'declares property hooks');
        }

        if ($type === null) {
            throw MappingException::unsupportedProperty($class, $name, 'has no type');
        }

        // `?T` and `T|null` both reflect as a named type; every other
        // union, and every intersection, does not.
        if (!$type instanceof ReflectionNamedType) {
            throw MappingException::unsupportedProperty($class, $name, "declares the composite type {$type}");
        }

        $typeName = $type->getName();
        $enum = null;

        if (in_array($typeName, self::SCALAR_TYPES, true)) {
            $scalar = $typeName;
        } elseif (!$type->isBuiltin() && is_subclass_of($typeName, BackedEnum::class)) {
            $enum = $typeName;
            $scalar = new ReflectionEnum($typeName)->getBackingType()?->getName() === 'int' ? 'int' : 'string';
        } else {
            throw MappingException::unsupportedProperty(
                $class,
                $name,
                "declares {$typeName}, which is not string, int, float, bool or a backed enum",
            );
        }

        $column = ($property->getAttributes(Column::class)[0] ?? null)?->newInstance()->name ?? self::snakeCase($name);

        if (preg_match('/^' . self::IDENTIFIER . '$/D', $column) !== 1) {
            throw MappingException::invalidColumn($class, $name, $column);
        }

        /** @var 'string'|'int'|'float'|'bool' $scalar */
        return ['name' => $name, 'column' => $column, 'type' => $scalar, 'nullable' => $type->allowsNull(), 'enum' => $enum];
    }

    /**
     * `ArticleCategory` to `article_category`, `publishedAt` to
     * `published_at`, `HTMLPage` to `html_page`. No pluralization.
     */
    private static function snakeCase(string $name): string
    {
        return strtolower((string) preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'], '$1_$2', $name));
    }
}
