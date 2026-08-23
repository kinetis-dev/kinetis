<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Delete implements RouteAttribute
{
    public function __construct(
        private string $path,
        private int $status = 200,
    ) {}

    #[\Override]
    public function httpMethod(): string
    {
        return 'DELETE';
    }

    #[\Override]
    public function path(): string
    {
        return $this->path;
    }

    #[\Override]
    public function status(): int
    {
        return $this->status;
    }
}
