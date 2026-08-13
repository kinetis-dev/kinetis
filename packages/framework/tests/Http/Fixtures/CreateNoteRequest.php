<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * $title deliberately carries no constraint attributes: an explicitly-null
 * value for it must be caught by Hydrator's own "must not be null." check,
 * not incidentally by a constraint that happens to reject null.
 */
final readonly class CreateNoteRequest
{
    public function __construct(
        public string $title,
        public ?string $subtitle,
    ) {}
}
