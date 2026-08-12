<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Address is a real, instantiable class — but its own required
 * constructor parameter has no default, so autowiring it fails deep
 * inside Autowire::instantiate()'s recursive resolution, not because
 * Address itself is unregistered. Proves the default-value fallback
 * applies to *that* failure too, not just a plain NotFoundException for
 * a missing binding.
 */
final class WithOptionalUnresolvableDependency
{
    public function __construct(
        public readonly ?Unresolvable $addr = null,
    ) {}
}
