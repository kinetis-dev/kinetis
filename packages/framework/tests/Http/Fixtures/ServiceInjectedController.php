<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Query;

final readonly class ServiceInjectedController
{
    /**
     * @return array<string, mixed>
     */
    #[Get('/scoped')]
    public function scoped(ScopedValue $value): array
    {
        return ['label' => $value->label];
    }

    /**
     * @return array<string, mixed>
     */
    #[Get('/scoped-optional')]
    public function optional(?ScopedValue $value = null): array
    {
        return ['label' => $value?->label ?? 'absent'];
    }

    /**
     * Optional, but its dependency graph is circular: a cycle is a
     * defect, never an absent value.
     *
     * @return array<string, mixed>
     */
    #[Get('/scoped-cyclic')]
    public function cyclic(?CyclicA $a = null): array
    {
        return ['resolved' => $a !== null];
    }

    /**
     * Optional, but registered and failing to construct — also a
     * defect rather than an absent value.
     *
     * @return array<string, mixed>
     */
    #[Get('/scoped-broken')]
    public function broken(?BrokenService $service = null): array
    {
        return ['resolved' => $service !== null];
    }

    /**
     * A class-typed parameter alongside every other source, proving the
     * container is consulted last and shadows none of them.
     *
     * @return array<string, mixed>
     */
    #[Post('/scoped/{id}')]
    public function mixed(
        int $id,
        #[Query] string $sort,
        #[Body] CreateUserRequest $user,
        ScopedValue $value,
    ): array {
        return ['id' => $id, 'sort' => $sort, 'user' => $user->name, 'label' => $value->label];
    }
}
