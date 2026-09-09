<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\ObjectConstraints\AtLeastOneProvided;

/**
 * The rule names `titel`, and the constructor declares `title`. Nothing
 * about the request is wrong: the typo would make every update fail the
 * rule, and would publish a `required` clause for a member the closed
 * object refuses. It is a mistake in the attribute, so it fails where
 * the rules are read.
 */
#[AtLeastOneProvided('titel')]
final readonly class UnknownObjectRuleFieldRequest
{
    public function __construct(
        public string|Absent $title = Absent::Value,
    ) {}
}
