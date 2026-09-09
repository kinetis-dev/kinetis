<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\ObjectConstraints\SameAs;

/**
 * The confirmation pair, on non-public promoted fields: `SameAs` reads
 * both through reflection, so a DTO keeping its data private needs no
 * getter and nothing bypasses its constructor.
 *
 * `confirmation` is a presence union so the "only compare what was
 * supplied" rule can be exercised without the DTO refusing to construct.
 */
#[SameAs('confirmation', 'password')]
final readonly class ChangePasswordRequest
{
    public function __construct(
        private string $password,
        private string|Absent $confirmation = Absent::Value,
    ) {}

    public function password(): string
    {
        return $this->password;
    }
}
