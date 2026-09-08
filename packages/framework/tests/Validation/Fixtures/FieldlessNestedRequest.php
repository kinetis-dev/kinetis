<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * A nested field and a #[ListOf] element both typed as a class that
 * hydrates from no fields at all, so the object/array distinction is the
 * only thing keeping an empty JSON array out of either.
 */
final readonly class FieldlessNestedRequest
{
    public function __construct(
        public NoConstructorFixture $settings,
        #[ListOf(NoConstructorFixture::class)]
        public array $extras,
    ) {}
}
