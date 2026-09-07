<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * A PSR-11 container from outside Kinetis: has() is the only question it
 * can be asked about availability, and get() the only way to resolve.
 * Both are counted so a test can show which one was used.
 */
final class ExternalPsrContainer implements ContainerInterface
{
    public int $hasCalls = 0;

    public int $getCalls = 0;

    /**
     * @param Closure(string): mixed $resolver
     */
    public function __construct(
        private readonly bool $answer,
        private readonly Closure $resolver,
    ) {}

    #[\Override]
    public function has(string $id): bool
    {
        $this->hasCalls++;

        return $this->answer;
    }

    #[\Override]
    public function get(string $id): mixed
    {
        $this->getCalls++;

        return ($this->resolver)($id);
    }
}
