<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use RuntimeException;

/**
 * Hydrator::typeMismatchMessage() reached a builtin type name it has no
 * arm for at all — every one of the twelve builtin type names
 * `ReflectionNamedType` can actually attach to a parameter (see
 * JsonSchema::forType()'s own docblock for the audited list) has an
 * explicit arm there, so this is never a real-world scalarType,
 * only a defensive backstop for a future PHP version introducing a new
 * builtin type name, or a caller passing a value this method never
 * derived from real reflection. Thrown rather than silently returning
 * null (accept-anything) — that permissive default is the exact
 * fail-open pattern that let `object`/`callable`/`iterable`/`null`/
 * `true`/`false` reach a raw constructor unchecked before this class's
 * own audit closed each of them individually; an unrecognized type
 * getting the same silent treatment would just be the identical bug
 * waiting for the next new type PHP ever adds.
 */
final class UnsupportedScalarTypeException extends RuntimeException
{
    public static function forType(string $scalarType): self
    {
        return new self(
            "Hydrator has no type-mismatch policy for the builtin type \"{$scalarType}\" — this should be "
            . 'unreachable for a type derived from real reflection. See JsonSchema::forType()\'s docblock '
            . 'for the complete, audited list of builtin type names Hydrator supports.',
        );
    }
}
