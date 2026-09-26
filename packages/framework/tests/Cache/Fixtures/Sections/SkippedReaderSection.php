<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Sections;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

final class SkippedReaderSection implements CacheableDiscoveryInterface
{
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        return ['upstream' => $context->compiled(NotASection::class)];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self();
    }
}
