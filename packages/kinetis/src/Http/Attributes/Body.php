<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Marks a controller-method parameter as bound to the decoded JSON request
 * body. The parameter's type is the DTO class Dispatcher hydrates and
 * validates before the controller ever runs.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Body {}
