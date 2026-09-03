<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * An explicit "this value came from a JSON *object* on the wire" marker
 * — the one piece of information `json_decode(..., associative: true)`
 * permanently erases: a JSON object (`{"0":"a","1":"b"}`) and a JSON
 * array (`["a","b"]`) both decode to the identical PHP array, and once
 * that's happened, `array_is_list()` cannot tell them apart — it's true
 * for both. `Kinetis\Validation\JsonTree::convert()` is what produces
 * this, by decoding with `json_decode(..., associative: false)` first
 * (a real `stdClass`/array distinction PHP's own decoder already makes
 * for free) and wrapping every `stdClass` node it finds, so
 * `Hydrator::typeMismatchMessage()`'s array/iterable/`#[ListOf]` checks
 * can reject an object-shaped wire value outright, regardless of what
 * its own keys happen to look like.
 *
 * Deliberately a thin wrapper, not `Kinetis\Mcp\JsonObject` reused
 * directly: that class solves a narrower, protocol-specific problem
 * (JSON-RPC envelope structural validation) in a package that already
 * depends on this one — `Kinetis\Mcp` code converts into *this* marker
 * before handing a value to `Hydrator`, not the other way around.
 */
final readonly class JsonObject
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(private array $properties) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->properties;
    }
}
