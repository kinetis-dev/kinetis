<?php

declare(strict_types=1);

namespace Kinetis\Orm\Metadata;

use BackedEnum;
use DateTimeImmutable;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\Version;
use Kinetis\Orm\Exception\MappingException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The mapping of an explicit set of entity classes, held as plain data:
 * class names, table and column names, type names and flags.
 * toArray() is exactly what fromArray() accepts, so a build step can export
 * the mapping and a worker can load it without scanning a directory.
 *
 * Both constructors reflect each named class and validate it against its
 * current source, so a registry never describes a class as it no longer
 * is. Nothing is cached beyond the instance.
 *
 * A #[BelongsTo] property maps its foreign-key column: its target is an
 * entity of the same registry, and its type is that target's identifier
 * type. A #[HasOne] or #[HasMany] property maps no column and is listed
 * among the inverses instead: its target is an entity of the same registry
 * whose #[BelongsTo] property mappedBy references the declaring class.
 *
 * A property declared exactly DateTimeImmutable, nullable or not, has the
 * type timestamp; DateTime, DateTimeInterface and subclasses of
 * DateTimeImmutable are refused as declared types.
 *
 * An owned inverse relationship is the aggregate ownership edge to its
 * target. Each entity class is the target of at most one of them, so one
 * mapping alone decides how a row is discovered, removed and orphaned.
 *
 * @phpstan-type PropertyMapping array{name: string, column: string, type: 'string'|'int'|'float'|'bool'|'timestamp', nullable: bool, enum: class-string<BackedEnum>|null, target: class-string|null}
 * @phpstan-type InverseMapping array{name: string, kind: 'hasOne'|'hasMany', target: class-string, mappedBy: string, nullable: bool, owned: bool}
 * @phpstan-type EntityMapping array{class: class-string, table: string, id: string, generated: bool, version: string|null, properties: list<PropertyMapping>, inverses: list<InverseMapping>}
 * @psalm-type PropertyMapping = array{name: string, column: string, type: 'string'|'int'|'float'|'bool'|'timestamp', nullable: bool, enum: class-string<BackedEnum>|null, target: class-string|null}
 * @psalm-type InverseMapping = array{name: string, kind: 'hasOne'|'hasMany', target: class-string, mappedBy: string, nullable: bool, owned: bool}
 * @psalm-type EntityMapping = array{class: class-string, table: string, id: string, generated: bool, version: string|null, properties: list<PropertyMapping>, inverses: list<InverseMapping>}
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

        // A relationship's target and type, and an inverse relationship's
        // mappedBy property, resolve once every class is mapped. $owned
        // holds the one owned inverse each target class may have, so the
        // second one is refused whichever class declares it.
        $owned = [];

        foreach ($entities as $class => $mapping) {
            foreach ($mapping['properties'] as $i => $property) {
                if ($property['target'] === null) {
                    continue;
                }

                $target = $entities[$property['target']] ?? throw MappingException::relationship(
                    $class,
                    $property['name'],
                    "{$property['target']} is not an entity in this MetadataRegistry",
                );

                /** @var 'int'|'string' $type an identifier is typed int or string */
                $type = array_column($target['properties'], 'type', 'name')[$target['id']];
                $property['type'] = $type;
                $mapping['properties'][$i] = $property;
            }

            foreach ($mapping['inverses'] as $inverse) {
                $target = $entities[$inverse['target']] ?? throw MappingException::inverse(
                    $class,
                    $inverse['name'],
                    "{$inverse['target']} is not an entity in this MetadataRegistry",
                );
                $mappedBy = $inverse['mappedBy'];
                $owning = array_column($target['properties'], null, 'name')[$mappedBy] ?? null;
                $reason = match (true) {
                    $owning === null => "mappedBy names \"{$mappedBy}\", which is not a mapped property of {$target['class']}",
                    $owning['target'] === null => "mappedBy names {$target['class']}::\${$mappedBy}, which is not a #[BelongsTo] relationship",
                    $owning['target'] !== $class => "mappedBy names {$target['class']}::\${$mappedBy}, which references {$owning['target']}, not {$class}",
                    default => null,
                };

                if ($reason !== null) {
                    throw MappingException::inverse($class, $inverse['name'], $reason);
                }

                if (!$inverse['owned']) {
                    continue;
                }

                [$ownerClass, $ownerProperty, $ownerMappedBy] = $owned[$inverse['target']] ?? [null, null, null];

                if ($ownerClass !== null) {
                    throw MappingException::inverse($class, $inverse['name'], $ownerMappedBy === $mappedBy
                        ? "it and {$ownerClass}::\${$ownerProperty} both own {$target['class']}::\${$mappedBy}, and one "
                            . '#[BelongsTo] property has at most one owned inverse relationship'
                        : "{$target['class']} is already owned through {$ownerClass}::\${$ownerProperty}, and an entity "
                            . 'class has at most one owned inverse relationship in a MetadataRegistry');
                }

                $owned[$inverse['target']] = [$class, $inverse['name'], $mappedBy];
            }

            $entities[$class] = $mapping;
        }

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
        $inverses = [];
        $columns = [];
        $ids = [];
        $versions = [];
        $generated = false;

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $type = self::type($name, $property);
            $inverse = self::inverse($name, $property, $type);

            if ($inverse !== null) {
                $inverses[] = $inverse;

                continue;
            }

            $mapping = self::property($name, $property, $type);
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

        if (
            $properties[$id]['enum'] !== null
            || $properties[$id]['target'] !== null
            || !in_array($properties[$id]['type'], ['int', 'string'], true)
        ) {
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
            'inverses' => $inverses,
        ];
    }

    /**
     * The refusals every non-static property shares, column or inverse
     * relationship.
     */
    private static function type(string $class, ReflectionProperty $property): ReflectionNamedType
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

        return $type;
    }

    /**
     * @return PropertyMapping
     */
    private static function property(string $class, ReflectionProperty $property, ReflectionNamedType $type): array
    {
        $name = $property->getName();
        $relationship = ($property->getAttributes(BelongsTo::class)[0] ?? null)?->newInstance();

        if ($relationship !== null) {
            return self::relationship($class, $property, $type, $relationship);
        }

        $typeName = $type->getName();
        $enum = null;

        if (in_array($typeName, self::SCALAR_TYPES, true)) {
            $scalar = $typeName;
        } elseif ($typeName === DateTimeImmutable::class) {
            $scalar = 'timestamp';
        } elseif (!$type->isBuiltin() && is_subclass_of($typeName, BackedEnum::class)) {
            $enum = $typeName;
            $scalar = new ReflectionEnum($typeName)->getBackingType()?->getName() === 'int' ? 'int' : 'string';
        } else {
            throw MappingException::unsupportedProperty(
                $class,
                $name,
                "declares {$typeName}, which is not string, int, float, bool, DateTimeImmutable or a backed enum",
            );
        }

        $column = ($property->getAttributes(Column::class)[0] ?? null)?->newInstance()->name ?? self::snakeCase($name);

        if (preg_match('/^' . self::IDENTIFIER . '$/D', $column) !== 1) {
            throw MappingException::invalidColumn($class, $name, $column);
        }

        /** @var 'string'|'int'|'float'|'bool'|'timestamp' $scalar */
        return ['name' => $name, 'column' => $column, 'type' => $scalar, 'nullable' => $type->allowsNull(), 'enum' => $enum, 'target' => null];
    }

    /**
     * An unloaded relationship is an uninitialized property, so it has no
     * default value. Its type is resolved by fromClasses() once the target
     * is mapped.
     *
     * @return PropertyMapping
     */
    private static function relationship(
        string $class,
        ReflectionProperty $property,
        ReflectionNamedType $type,
        BelongsTo $relationship,
    ): array {
        $name = $property->getName();
        $reason = match (true) {
            $type->isBuiltin() => "it declares {$type->getName()}, which is not an entity class",
            $property->getAttributes(Column::class) !== [] => 'it also carries #[Column]; name its foreign-key column with #[BelongsTo(column: ...)]',
            $property->getAttributes(Id::class) !== [] => 'it also carries #[Id]',
            $property->getAttributes(Version::class) !== [] => 'it also carries #[Version]',
            $property->hasDefaultValue() => 'it declares a default value, and an unloaded relationship is uninitialized',
            default => null,
        };

        if ($reason !== null) {
            throw MappingException::relationship($class, $name, $reason);
        }

        $column = $relationship->column ?? self::snakeCase($name) . '_id';

        if (preg_match('/^' . self::IDENTIFIER . '$/D', $column) !== 1) {
            throw MappingException::invalidColumn($class, $name, $column);
        }

        /** @var class-string $target */
        $target = $type->getName() === 'self' ? $class : $type->getName();

        return ['name' => $name, 'column' => $column, 'type' => 'int', 'nullable' => $type->allowsNull(), 'enum' => null, 'target' => $target];
    }

    /**
     * An unloaded inverse relationship is an uninitialized property, so it
     * has no default value: an initialized one is never loaded. Its mappedBy
     * property is resolved by fromClasses() once the target is mapped.
     *
     * @return InverseMapping|null null for a property carrying neither #[HasOne] nor #[HasMany]
     */
    private static function inverse(string $class, ReflectionProperty $property, ReflectionNamedType $type): ?array
    {
        $hasOne = ($property->getAttributes(HasOne::class)[0] ?? null)?->newInstance();
        $hasMany = ($property->getAttributes(HasMany::class)[0] ?? null)?->newInstance();

        if ($hasOne === null && $hasMany === null) {
            return null;
        }

        $name = $property->getName();
        $reason = match (true) {
            $hasOne !== null && $hasMany !== null => 'it carries both #[HasOne] and #[HasMany]',
            $property->getAttributes(BelongsTo::class) !== [] => 'it also carries #[BelongsTo]',
            $property->getAttributes(Column::class) !== [] => 'it also carries #[Column]',
            $property->getAttributes(Id::class) !== [] => 'it also carries #[Id]',
            $property->getAttributes(Version::class) !== [] => 'it also carries #[Version]',
            $hasMany === null && $type->isBuiltin() => "#[HasOne] needs a type naming one entity class, and it declares {$type}",
            $hasMany !== null && ($type->getName() !== 'array' || $type->allowsNull()) => "#[HasMany] needs the type array, and it declares {$type}",
            $property->hasDefaultValue() => 'it declares a default value, and an unloaded inverse relationship is uninitialized',
            default => null,
        };

        if ($reason !== null) {
            throw MappingException::inverse($class, $name, $reason);
        }

        if ($hasMany !== null) {
            return [
                'name' => $name,
                'kind' => 'hasMany',
                'target' => $hasMany->target,
                'mappedBy' => $hasMany->mappedBy,
                'nullable' => false,
                'owned' => $hasMany->owned,
            ];
        }

        /**
         * @var HasOne $hasOne the property carries exactly one of the two
         * @var class-string $target
         */
        $target = $type->getName() === 'self' ? $class : $type->getName();

        return [
            'name' => $name,
            'kind' => 'hasOne',
            'target' => $target,
            'mappedBy' => $hasOne->mappedBy,
            'nullable' => $type->allowsNull(),
            'owned' => $hasOne->owned,
        ];
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
