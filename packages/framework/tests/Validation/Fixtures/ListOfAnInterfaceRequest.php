<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;
use Psr\Http\Message\StreamInterface;

final readonly class ListOfAnInterfaceRequest
{
    public function __construct(
        #[ListOf(StreamInterface::class)]
        public array $streams,
    ) {}
}
