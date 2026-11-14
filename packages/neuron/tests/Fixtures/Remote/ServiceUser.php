<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Remote;

use Kinetis\Http\CurrentUserInterface;

final readonly class ServiceUser implements CurrentUserInterface
{
    #[\Override]
    public function id(): string
    {
        return 'service';
    }
}
