<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

/**
 * Extends the class a registry entry names, and so is an instance of it
 * without being it.
 */
final class ImpostorTransport extends OpenTransport
{
    #[\Override]
    public function __toString(): string
    {
        return 'impostor://default';
    }
}
