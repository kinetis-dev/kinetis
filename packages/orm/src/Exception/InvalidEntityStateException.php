<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;
use Throwable;

/**
 * An entity operation outside the unit-of-work lifecycle — an unmapped
 * class, an object the EntityManager does not hold, an incomplete entity,
 * a conflicting or changed identifier, a changed or exhausted version, a
 * call while flush() runs, a locking read outside a transaction session, a
 * nested session, a call after a session's flush or failure — or a flush
 * whose rows disagree with the entities it writes. A message names the
 * class and property, never an identifier or version value: an identifier
 * can be a secret.
 */
final class InvalidEntityStateException extends RuntimeException
{
    public static function lockOutsideTransaction(): self
    {
        return new self(
            'lockForUpdate() and lockForShare() need an EntityManager bound to a transaction: outside one the lock '
            . 'ends with its statement. Read the entity inside OrmFactory::transaction().',
        );
    }

    public static function nestedTransaction(): self
    {
        return new self(
            'This Fiber is already inside OrmFactory::transaction() on this factory, and sessions do not nest. Use '
            . 'the EntityManager the running callback received.',
        );
    }

    public static function sessionFlushed(): self
    {
        return new self(
            "This EntityManager flushed its work inside OrmFactory::transaction(), and that flush is the callback's "
            . 'final ORM operation. Until the callback returns it accepts only close() and isClosed().',
        );
    }

    public static function sessionFailed(Throwable $failure): self
    {
        return new self(
            'An ORM operation inside this OrmFactory::transaction() callback failed, so the transaction rolls back '
            . 'once the callback returns and this EntityManager accepts only close() and isClosed(). getPrevious() '
            . 'is that failure.',
            0,
            $failure,
        );
    }

    public static function unmapped(string $class): self
    {
        return new self("{$class} is not an entity in this OrmFactory's MetadataRegistry, so it cannot be persisted.");
    }

    public static function notHeld(string $class): self
    {
        return new self(
            "This EntityManager does not hold this {$class} object, so it cannot remove it. Remove an entity the "
            . 'same manager loaded or persisted.',
        );
    }

    public static function flushInProgress(): self
    {
        return new self(
            'This EntityManager is flushing. Until flush() returns it accepts only close() and isClosed(), '
            . 'including from code the flush itself runs, such as SQL instrumentation.',
        );
    }

    public static function uninitialized(string $class, string $property): self
    {
        return new self("{$class}::\${$property} is not initialized. Every mapped property needs a value to be written.");
    }

    public static function nullIdentifier(string $class): self
    {
        return new self(
            "{$class}'s assigned identifier is null. Set it before persist(), or mark the property "
            . '#[Id(generated: true)] for the database to generate it.',
        );
    }

    public static function generatedIdentifierSet(string $class): self
    {
        return new self("{$class}'s generated identifier is not null. The database generates it: persist the entity with null.");
    }

    public static function identityConflict(string $class): self
    {
        return new self("This EntityManager already holds another {$class} object with the same identifier.");
    }

    public static function identifierChanged(string $class): self
    {
        return new self(
            "The identifier of a {$class} object changed while this EntityManager held it. An identifier is fixed "
            . 'once an entity is loaded or persisted; persist a new object for another row.',
        );
    }

    public static function versionChanged(string $class): self
    {
        return new self(
            "The version of a {$class} object changed while this EntityManager held it. The manager advances a "
            . 'loaded or flushed version itself; clear the manager and load the entity again to see a newer one.',
        );
    }

    public static function versionExhausted(string $class): self
    {
        return new self("A {$class} entity's version is PHP_INT_MAX, so an UPDATE cannot advance it.");
    }

    public static function invalidGeneratedIdentifier(string $class): self
    {
        return new self(
            "An insert of a {$class} entity reported a generated identifier that is null or not an int within "
            . "PHP's range.",
        );
    }

    public static function missingRow(string $class, string $statement): self
    {
        return new self("The row of a {$class} entity does not exist, so its {$statement} affected no row.");
    }

    public static function ambiguousRow(string $class, string $statement, int $rows): self
    {
        return new self(
            "The {$statement} of one {$class} entity by its identifier affected {$rows} rows: its identifier column "
            . 'is not unique in the table.',
        );
    }
}
