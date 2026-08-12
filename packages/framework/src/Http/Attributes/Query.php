<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Marks a controller-method parameter as bound to a query-string value of
 * the same name, cast to the parameter's scalar type.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Query {}
