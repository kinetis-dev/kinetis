<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A bare `int` field carrying no constraint attribute at all, so the
 * accepted-value policy is the only thing under test.
 */
final readonly class BoundlessIntFieldRequest
{
    public function __construct(
        public int $count,
    ) {}
}
