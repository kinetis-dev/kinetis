<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Deliberately not autowirable: its one constructor parameter is a
 * string with no default, so the container can only supply it if
 * something registered an instance. That makes "the middleware ran" and
 * "the middleware did not run" distinguishable in a test.
 */
final readonly class ScopedValue
{
    public function __construct(public string $label) {}
}
