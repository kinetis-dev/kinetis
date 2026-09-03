<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\StrictPlugin;

use Kinetis\Cache\CacheableDiscoveryInterface;

/**
 * A CacheableDiscoveryInterface implementation whose fromArray() counts
 * how many times it has constructed an instance — the same
 * "constructed exactly once" proof technique
 * ConstructionCountingQueueableListener already establishes for
 * queued listeners, applied here to prove BootSequence's cache-or-
 * compile decision reconstructs plugin instances exactly once, never
 * once to validate and again to actually bind.
 */
final class CountingCacheableDiscovery implements CacheableDiscoveryInterface
{
    public static int $constructions = 0;

    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return ['value' => 'compiled'];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        self::$constructions++;

        return new self();
    }
}
