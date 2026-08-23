<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Dispatched at each pipeline stage a ping passes through.
 */
final readonly class ActionEvent
{
    public function __construct(
        public string $stage,
        public ?int $id = null,
        public ?string $scenario = null,
    ) {}
}
