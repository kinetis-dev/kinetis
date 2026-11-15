<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Tools;

use Kinetis\Validation\Constraints\Email;

final readonly class Contact
{
    public function __construct(
        #[Email]
        public string $email,
        public ?string $phone = null,
    ) {}
}
