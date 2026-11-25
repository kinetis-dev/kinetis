<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Attribute;

/**
 * Declares a DTO constructor parameter, or an MCP tool method parameter,
 * typed `array` as a JSON *object* map rather than a JSON array — the
 * shape a plain `array` field refuses, since PHP's `array` type alone
 * carries no object-vs-list distinction for Hydrator to reflect on and
 * the wire contract has to pick one. Hydrator accepts a JSON object of
 * any keys and hands the parameter its members as a PHP array keyed by
 * member name; a JSON array, a scalar, or a null the type does not allow
 * is a validation error on that parameter's own path.
 *
 * By default every JSON object nested inside the map reaches the
 * parameter as a plain PHP array too. With $keepObjects, every nested
 * JSON object becomes a stdClass and every JSON array a list, at any
 * depth, so `{}` and `[]` stay distinct when the value is encoded again.
 *
 * The admitted value must carry the JsonObject provenance marker
 * JsonTree::convert() puts on every JSON object — the one thing a
 * decoded PHP array cannot reconstruct, since `{}` and `[]` decode
 * identically. A source that never carried the distinction in the first
 * place (a form-encoded body, a hand-built array passed straight to
 * Hydrator::hydrate()) therefore cannot fill an #[ObjectMap] property.
 *
 * Mutually exclusive with #[ListOf]: one admits a JSON object, the
 * other a JSON array, and a property cannot be both.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ObjectMap
{
    public function __construct(public bool $keepObjects = false) {}
}
