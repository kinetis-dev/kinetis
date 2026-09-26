<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Sections;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

/**
 * A real section that no installed package declares.
 */
final class UndeclaredSection implements CacheableDiscoveryInterface
{
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        return [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self();
    }
}
