<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;

/**
 * An entity class, serialized metadata, a property name, a relationship
 * path, a predicate value, a loaded row, a missing relationship target or an
 * entity's property value to write outside the mapping contract. Every one
 * is thrown before SQL runs or before an entity is allocated, but a missing
 * relationship target, which is thrown after its select and before the
 * relationship is assigned. A message names the class, property and column,
 * and describes a value only by its kind: a column can hold a secret.
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

    public static function invalidTable(string $class, string $table): self
    {
        return new self(
            "{$class} maps to the table \"{$table}\", which is not one or more identifiers separated by dots. An "
            . 'identifier is ASCII letters, digits and underscores, not starting with a digit.',
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
        return new self("{$class} is not an entity in this OrmFactory's MetadataRegistry.");
    }

    public static function unknownProperty(string $class, string $property): self
    {
        return new self("{$class} has no mapped property \"{$property}\".");
    }

    public static function notARelation(string $class, string $property): self
    {
        return new self("{$class}::\${$property} is not a #[BelongsTo] relationship, so with() cannot load it.");
    }

    public static function invalidRelationPath(string $class, string $path): self
    {
        return new self(
            "\"{$path}\" is not a relationship path of {$class}: name #[BelongsTo] properties separated by dots, such "
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
