<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use DateTimeImmutable;

/**
 * A default PHP rebuilds on every evaluation, which a plan would capture
 * once and hand to every later request. Rejected where the plan is
 * derived — this fixture exists to prove that.
 */
final readonly class ObjectDefaultRequest
{
    public function __construct(
        public ?DateTimeImmutable $since = new DateTimeImmutable(),
    ) {}
}
