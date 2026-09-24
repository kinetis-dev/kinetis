<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Patch implements RouteAttribute
{
    /**
     * @param array<string,string> $where see RouteAttribute::where()
     */
    public function __construct(
        private string $path,
        private int $status = 200,
        private array $where = [],
    ) {}

    #[\Override]
    public function httpMethod(): string
    {
        return 'PATCH';
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

    #[\Override]
    public function where(): array
    {
        return $this->where;
    }
}
