<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\StrictPlugin;

use Kinetis\Cache\CacheableDiscoveryInterface;
use LogicException;

/**
 * A CacheableDiscoveryInterface implementation whose fromArray() throws
 * something that does NOT implement CacheArtifactExceptionInterface —
 * standing in for a genuine defect inside a plugin's own reconstruction
 * code (an undefined method call, a dependency failure), never a
 * data-shape problem. Exists so BootSequenceCacheTest can prove such a
 * failure propagates uncaught rather than being silently relabelled
 * "corrupt cache" and retried as a fresh compile.
 */
final readonly class BuggyCacheableDiscovery implements CacheableDiscoveryInterface
{
    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return [];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        throw new LogicException('BuggyCacheableDiscovery: a genuine defect, not a data-shape problem.');
    }
}
