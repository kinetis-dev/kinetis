<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * An explicit "this is a JSON object" marker for a caller building a
 * message directly rather than decoding one off the wire.
 *
 * `json_decode()` never needs this: it produces a `stdClass` for a JSON
 * object and a plain array for a JSON array, distinguishable even when
 * both are empty. A hand-written PHP array literal has no such pair —
 * `[]` is the only spelling for both an empty object and an empty list —
 * and the named-object members this protocol validates (`params`,
 * `_meta`, `capabilities`, `clientInfo`, `arguments`) admit only the
 * first. Wrapping a value here says "treat this as an object even with no
 * properties", which is what a real `{}` on the wire decodes to.
 *
 * {@see JsonRpcCodec::toObjectTree()} converts a tree holding these back
 * into the plain `stdClass`/array shape a consumer receives, so a marker
 * never reaches consumer code.
 */
final readonly class JsonObject
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(private array $properties = []) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->properties;
    }
}
