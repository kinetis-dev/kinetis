<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Sections;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

/**
 * Reads UpstreamSection twice: the second read must be the memoized
 * array, not a second compile.
 */
final class ConsumerSection implements CacheableDiscoveryInterface
{
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        $upstream = $context->compiled(UpstreamSection::class);
        $repeated = $context->compiled(UpstreamSection::class);

        SectionLog::$compiled[] = self::class;

        return ['upstream' => $upstream, 'repeated' => $repeated];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self();
    }
}
