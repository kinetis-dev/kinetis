<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\Constraints\NotBlank;
use Kinetis\Validation\ObjectConstraints\AtLeastOneProvided;

/**
 * The update half of the pair {@see CreateArticleRequest} opens: every
 * field is a presence union, so each one distinguishes "not mentioned"
 * from "sent". `summary` also names `null`, which is how a client clears
 * it; `title` does not, so an explicit null there is a violation rather
 * than a value.
 *
 * The class rule is what makes an update that says nothing at all a
 * client error instead of a silent no-op write.
 */
#[AtLeastOneProvided('title', 'summary')]
final readonly class UpdateArticleRequest
{
    public function __construct(
        #[NotBlank]
        public string|Absent $title = Absent::Value,
        public string|null|Absent $summary = Absent::Value,
    ) {}
}
