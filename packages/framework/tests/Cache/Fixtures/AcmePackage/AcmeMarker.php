<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\AcmePackage;

final readonly class AcmeMarker
{
    public function __construct(
        public string $source,
    ) {}
}
