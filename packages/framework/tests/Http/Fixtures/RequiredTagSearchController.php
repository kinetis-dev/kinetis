<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * An array-typed #[Query] parameter with no default: the repeated-key
 * form is the only thing that satisfies it, so any other spelling of the
 * same intent leaves it missing and the route answers 422.
 */
final readonly class RequiredTagSearchController
{
    #[Get('/required-tag-search')]
    public function search(#[Query] array $tags): array
    {
        return ['tags' => $tags];
    }
}
