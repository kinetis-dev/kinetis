<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Tools;

use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\ListOf;

/** A nested DTO, a backed enum, a typed list, and nullable and defaulted fields. */
final readonly class TicketRequest
{
    public function __construct(
        #[MinLength(3)]
        public string $title,
        public Contact $contact,
        #[ListOf('string')]
        public array $tags,
        public Priority $priority = Priority::Low,
        public ?string $note = null,
    ) {}
}
