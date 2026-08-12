<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\NotBlank;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;

final readonly class RegisterAccountRequest
{
    public function __construct(
        #[NotBlank]
        public string $username,
        #[MaxLength(20)]
        public string $bio,
        #[LessThan(120)]
        public int $age,
        #[In(['admin', 'member', 'guest'])]
        public string $role,
        #[Url]
        public string $website,
        #[Uuid]
        public string $referralId,
    ) {}
}
