<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

final readonly class StreamTag implements StreamTagInterface
{
    public function __construct(
        public string $value,
    ) {}
}
