<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\NotBlank;

/**
 * The create half of a create/update pair: every field the operation
 * needs is declared without a default, so omitting one is a `required`
 * failure. Its update counterpart is {@see UpdateArticleRequest}.
 */
final readonly class CreateArticleRequest
{
    public function __construct(
        #[NotBlank]
        public string $title,
        public ?string $summary,
    ) {}
}
