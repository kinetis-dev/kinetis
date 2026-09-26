<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Sections;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

final class UpstreamSection implements CacheableDiscoveryInterface
{
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        SectionLog::$compiled[] = self::class;

        return ['source' => 'upstream:' . $context->projectRoot];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self();
    }
}
