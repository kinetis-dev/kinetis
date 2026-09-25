<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;

/**
 * An entity class, serialized metadata, a property name, a relationship
 * path, a predicate value, a loaded row, a missing or ambiguous relationship
 * target or an entity's property value to write outside the mapping
 * contract. Every one is thrown before SQL runs or before an entity is
 * allocated, but a missing or ambiguous relationship target, which is thrown
 * after its select and before the relationship is assigned. A message names
 * the class, property and column, and describes a value only by its kind: a
 * column can hold a secret.
 */
final class MappingException extends RuntimeException
{
    public static function notAClass(mixed $class): self
    {
        $subject = is_string($class) ? "\"{$class}\"" : get_debug_type($class);

        return new self("{$subject} is not an existing class, so it cannot be mapped as an entity.");
    }

    public static function notAnEntity(string $class): self
    {
        return new self(
            "{$class} has no #[Kinetis\\Orm\\Attributes\\Entity] attribute. Mark it as an entity or leave it out of the "
            . 'entity list.',
        );
    }

    public static function duplicateEntity(string $class): self
    {
        return new self("{$class} is listed more than once in the entity list.");
    }

    public static function unsupportedEntity(string $class, string $reason): self
    {
        return new self("{$class} cannot be mapped as an entity: {$reason}.");
    }

    public static function unsupportedProperty(string $class, string $property, string $reason): self
    {
        return new self(
            "{$class}::\${$property} cannot be mapped: it {$reason}. Every non-static property of an entity is mapped.",
        );
    }

    public static function identifier(string $class, string $reason): self
    {
        return new self("{$class} has no usable identifier: {$reason}.");
    }

    public static function version(string $class, string $reason): self
    {
        return new self("{$class} has no usable version: {$reason}.");
    }

    public static function relationship(string $class, string $property, string $reason): self
    {
        return new self("{$class}::\${$property} is not a usable #[BelongsTo] relationship: {$reason}.");
    }

    public static function inverse(string $class, string $property, string $reason): self
    {
        return new self("{$class}::\${$property} is not a usable #[HasOne] or #[HasMany] relationship: {$reason}.");
    }

    public static function join(string $class, string $property, string $reason): self
    {
        return new self("{$class}::\${$property} is not a usable #[ManyToMany] relationship: {$reason}.");
    }

    public static function invalidTable(string $class, string $table): self
    {
        return new self(
            "{$class} maps to the table \"{$table}\", which is not one or more identifiers separated by dots. An "
            . 'identifier is ASCII letters, digits and underscores, not starting with a digit.',
        );
    }

    public static function invalidConnection(string $class, string $connection): self
    {
        return new self(
            "{$class} names the connection \"{$connection}\", which is not a connection name: lowercase ASCII letters "
            . 'and digits, starting with a letter.',
        );
    }

    public static function reservedConnection(string $class, string $connection): self
    {
        return new self(
            "{$class} names the connection \"{$connection}\", which is reserved: its scoped DB_NAME key would be "
            . 'DB_APP_NAME, the default connection\'s application-name key. Name the connection otherwise.',
        );
    }

    public static function invalidColumn(string $class, string $property, string $column): self
    {
        return new self(
            "{$class}::\${$property} maps to the column \"{$column}\", which is not an identifier: ASCII letters, "
            . 'digits and underscores, not starting with a digit. Name it with #[Column], or with '
            . '#[BelongsTo(column: ...)] for a relationship.',
        );
    }

    public static function duplicateColumn(string $class, string $first, string $second, string $column): self
    {
        return new self(
            "{$class}::\${$first} and {$class}::\${$second} both map to the column \"{$column}\". Column names are "
            . 'compared case-insensitively, the rule every supported database can hold.',
        );
    }

    public static function malformedMetadata(string $reason): self
    {
        return new self("The entity metadata is malformed: {$reason}. Rebuild it from MetadataRegistry::toArray().");
    }

    public static function staleMetadata(string $class): self
    {
        return new self(
            "The entity metadata for {$class} does not match that class's current source. Rebuild it from "
            . 'MetadataRegistry::toArray().',
        );
    }

    public static function unknownEntity(string $class): self
    {
        return new self(
            "{$class} is not an entity of this OrmFactory: it is not in the factory's MetadataRegistry, or it lives "
            . 'on another connection.',
        );
    }

    public static function unregisteredEntity(string $class): self
    {
        return new self("{$class} is not an entity in this MetadataRegistry.");
    }

    public static function unknownProperty(string $class, string $property): self
    {
        return new self("{$class} has no mapped property \"{$property}\".");
    }

    public static function notAColumn(string $class, string $property): self
    {
        return new self(
            "{$class}::\${$property} maps no column of its own table, so no predicate, order or cursor can name it. "
            . "Filter and page its targets through their own repository, an inverse relationship by the target's "
            . '#[BelongsTo] property and a #[ManyToMany] by its join table through builder().',
        );
    }

    public static function notARelation(string $class, string $property): self
    {
        return new self(
            "{$class}::\${$property} is not a #[BelongsTo], #[HasOne], #[HasMany] or #[ManyToMany] relationship, so "
            . 'with() cannot load it.',
        );
    }

    public static function invalidRelationPath(string $class, string $path): self
    {
        return new self(
            "\"{$path}\" is not a relationship path of {$class}: name relationship properties separated by dots, such "
            . 'as "author.organization".',
        );
    }

    /** @param class-string $target */
    public static function missingRelationTarget(string $class, string $property, string $column, string $target): self
    {
        return new self(
            "{$class}::\${$property} references a missing {$target} row: no row matches its \"{$column}\" foreign key.",
        );
    }

    /** @param class-string $target */
    public static function missingInverseTarget(string $class, string $property, string $target, string $column): self
    {
        return new self(
            "{$class}::\${$property} is not nullable, and no {$target} row references its entity through the "
            . "\"{$column}\" foreign key.",
        );
    }

    /** @param class-string $target */
    public static function ambiguousInverseTarget(string $class, string $property, string $target, string $column): self
    {
        return new self(
            "{$class}::\${$property} is a #[HasOne], and more than one {$target} row references its entity through the "
            . "\"{$column}\" foreign key. A #[HasOne] foreign-key column needs a unique constraint.",
        );
    }

    /** @param class-string $target */
    public static function missingJoinTarget(string $class, string $property, string $table, string $target): self
    {
        return new self(
            "{$class}::\${$property} has a \"{$table}\" row naming a {$target} row that does not exist. A join table "
            . 'needs a foreign key to each entity table.',
        );
    }

    public static function invalidJoinRow(string $class, string $property, string $table, string $column): self
    {
        return new self(
            "{$class}::\${$property} has a \"{$table}\" row whose \"{$column}\" column holds no identifier. Both join "
            . 'columns are NOT NULL foreign keys.',
        );
    }

    public static function missingColumn(string $class, string $property, string $column): self
    {
        return new self("A row loaded for {$class} has no \"{$column}\" column for {$class}::\${$property}.");
    }

    public static function nullIdentifier(string $class): self
    {
        return new self("A row loaded for {$class} has a null identifier.");
    }

    public static function invalidValue(string $class, string $property, string $expected, mixed $value): self
    {
        return new self("{$class}::\${$property} takes {$expected}, got " . get_debug_type($value) . '.');
    }

    /** @param class-string $enum */
    public static function unknownEnumCase(string $class, string $property, string $enum, mixed $value): self
    {
        return new self(
            "{$class}::\${$property} takes a {$enum} case, and the " . get_debug_type($value) . ' value names none.',
        );
    }
}
