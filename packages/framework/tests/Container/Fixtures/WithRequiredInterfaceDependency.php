<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Required and typed against an unbound interface: nothing stands in for
 * the absence, so the container reports it in its own terms.
 */
final class WithRequiredInterfaceDependency
{
    public function __construct(
        public readonly OptionalInterface $thing,
    ) {}
}
