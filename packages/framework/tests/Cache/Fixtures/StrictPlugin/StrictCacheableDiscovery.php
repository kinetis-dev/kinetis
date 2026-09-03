<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\StrictPlugin;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use RuntimeException;

/**
 * A CacheableDiscoveryInterface implementation whose fromArray() throws
 * (an exception implementing CacheArtifactExceptionInterface, per that
 * interface's own contract) for malformed data, the same way a package
 * genuinely validating its own cached shape (EventListenerRegistry::
 * fromArray() being core's own example) would — unlike AcmePackage's
 * own fixture, which tolerates anything via a blind (string) cast.
 * Exists so BootSequenceCacheTest can prove a plugin's own
 * reconstruction failure is classified as cache corruption, not just
 * EventListenerRegistry's.
 */
final readonly class StrictCacheableDiscovery implements CacheableDiscoveryInterface
{
    public function __construct(
        public string $value,
    ) {}

    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return ['value' => 'compiled'];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!isset($data['value']) || !is_string($data['value'])) {
            throw new class ('StrictCacheableDiscovery: malformed cached data.') extends RuntimeException implements CacheArtifactExceptionInterface {};
        }

        return new self($data['value']);
    }
}
