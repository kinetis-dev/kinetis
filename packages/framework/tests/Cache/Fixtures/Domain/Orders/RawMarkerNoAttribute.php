<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Domain\Orders;

// A comment containing the raw "#[" byte sequence, but no attribute.
final class RawMarkerNoAttribute
{
    public function marker(): string
    {
        return '#[NotAnAttribute]';
    }
}
