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
 * `Hydrator::resolveScalar()`'s array/iterable check and its
 * `#[ListOf]` counterpart can reject an object-shaped wire value
 * outright, regardless of what its own keys happen to look like.
 *
 * Deliberately a thin wrapper, not `Kinetis\McpProtocol\JsonObject`
 * reused directly: that class solves a narrower, protocol-specific
 * problem (writing a JSON object in a hand-built JSON-RPC message) in a
 * framework-agnostic package this one knows nothing about —
 * `Kinetis\Mcp` code converts a decoded tool argument into *this* marker
 * before handing it to `Hydrator`, not the other way around.
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
