<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * Converts between `json_decode(..., associative: false)`'s own
 * `stdClass`/array-distinguishing tree and the two shapes
 * `Kinetis\Validation\Hydrator` needs at different points: `convert()`
 * preserves the object-vs-array distinction (wrapping every `stdClass`
 * node in a `JsonObject` marker) for the type-mismatch check itself;
 * `unwrap()` discards that distinction again, recursively, for a value
 * that already passed the check and is about to be handed to
 * application code (a `mixed`-typed field, or any array/iterable
 * field's own nested contents) — which must never see a `JsonObject`
 * wrapper leak into it, only the plain nested arrays/scalars it always
 * received before this class existed.
 */
final class JsonTree
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * $node is whatever `json_decode($json, associative: false)`
     * produced (or a value already shaped that way) — a `stdClass` for
     * each JSON object, a plain PHP array for each JSON array, and a
     * scalar/null everywhere else. Every `stdClass` becomes a
     * `JsonObject` wrapping its own recursively-converted properties;
     * every plain array (only ever a genuine JSON array once decoded
     * this way — `json_decode()`'s own array-mode always produces
     * sequential 0,1,2,... keys for one) is walked the same way,
     * element by element; anything else passes through unchanged.
     */
    public static function convert(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            return new JsonObject(array_map(self::convert(...), get_object_vars($node)));
        }

        if (is_array($node)) {
            return array_map(self::convert(...), $node);
        }

        return $node;
    }

    /**
     * The inverse of convert(), applied once a value has already
     * survived Hydrator's own type-mismatch check and is about to
     * become the value application code actually sees: every
     * `JsonObject` becomes a plain array again, recursively, so a
     * `mixed`-typed field (or an array-typed field's own nested
     * contents, which convert() may still have marked at any depth)
     * receives the identical plain-array tree it always did before
     * object/array provenance existed at all.
     */
    public static function unwrap(mixed $node): mixed
    {
        if ($node instanceof JsonObject) {
            return array_map(self::unwrap(...), $node->toArray());
        }

        if (is_array($node)) {
            return array_map(self::unwrap(...), $node);
        }

        return $node;
    }
}
