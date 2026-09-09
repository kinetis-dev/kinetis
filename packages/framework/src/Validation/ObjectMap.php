<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Attribute;

/**
 * Declares a constructor parameter typed `array` as a JSON *object* map
 * rather than a JSON array — the shape a plain `array` field refuses,
 * since PHP's `array` type alone carries no object-vs-list distinction
 * for Hydrator to reflect on and the wire contract has to pick one.
 * Hydrator accepts a JSON object of any keys and hands the property its
 * plain array form; a JSON array, a scalar, or a null is a validation
 * error on that property's own path.
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
final readonly class ObjectMap {}
