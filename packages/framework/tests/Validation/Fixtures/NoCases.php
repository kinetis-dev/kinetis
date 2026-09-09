<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A backed enum with no cases. Legal PHP — reflection reports it as
 * backed, with a backing type and an empty case list — and an input
 * domain nothing can satisfy, which is why a field or a list naming it
 * is refused where the definition is compiled.
 */
enum NoCases: string {}
