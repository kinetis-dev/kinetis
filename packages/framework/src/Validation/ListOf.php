<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Attribute;

/**
 * Declares what a constructor parameter typed `array` holds — PHP's
 * `array` type carries no element-type information for Hydrator to
 * reflect on otherwise. The named type is one of exactly four
 * families, and the whole list is admitted or refused as one:
 *
 * - a scalar spelling — `string`, `int`, `float`, `bool` — resolved
 *   element by element through the same source vocabulary a scalar
 *   field of that type gets;
 * - a backed enum class, whose element is its backing value resolved
 *   as that scalar and then turned into the case it names;
 * - an instantiable DTO class, hydrated from an object-shaped element
 *   exactly as a single nested DTO parameter is, or taken as given
 *   when the element already is an instance of it;
 * - exactly `Psr\Http\Message\UploadedFileInterface`, the repeated
 *   file control a `photos[]` multipart form sends. Every element is
 *   an uploaded file whose transport status passed, taken as given the
 *   way a single upload field's is.
 *
 * Anything else — an empty name, `array`, `iterable`, `mixed`, another
 * builtin, a name no class answers to, an empty backed enum, any other
 * interface, an abstract class — is refused where the hydration plan
 * is compiled and where a schema is generated, since no element could
 * ever satisfy it. See Hydrator::listItem().
 *
 * A scalar, backed-enum or uploaded-file list may carry {@see Each} to
 * run a rule against every element. A DTO list may not: its elements
 * carry their own fields' rules.
 *
 * This and #[Each] describe a DTO constructor field. A controller or
 * MCP tool method parameter binds neither.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    public function __construct(
        private string $type,
    ) {}

    public function itemType(): string
    {
        return $this->type;
    }
}
