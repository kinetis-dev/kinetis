<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Closure;

/**
 * A single service registration: how to build it, and whether the built
 * instance should be cached (shared) or rebuilt on every resolution.
 */
final class Binding
{
    private ?object $instance = null;

    public function __construct(
        public readonly Closure $factory,
        public readonly bool $shared = true,
    ) {}

    public function resolved(): ?object
    {
        return $this->instance;
    }

    public function remember(object $instance): void
    {
        $this->instance = $instance;
    }
}
