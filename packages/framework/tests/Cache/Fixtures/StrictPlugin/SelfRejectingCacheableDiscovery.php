<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\StrictPlugin;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;

/**
 * A CacheableDiscoveryInterface implementation that compiles one shape
 * and reconstructs another, so its own fromArray() rejects its own
 * compile() output. The rejection is the classified
 * CacheArtifactExceptionInterface that interface's contract requires,
 * which makes an artifact carrying this section one every boot treats as
 * corrupt and recompiles into the identical failure.
 *
 * Exists so BuildCommandTest can prove `kinetis build` reconstructs what
 * it compiled before publishing any of it.
 */
final readonly class SelfRejectingCacheableDiscovery implements CacheableDiscoveryInterface
{
    public const string REJECTION = 'compile() emits "values", fromArray() requires "value"';

    public function __construct(
        public string $value,
    ) {}

    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return ['values' => ['compiled']];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!isset($data['value']) || !is_string($data['value'])) {
            throw InvalidCacheArtifactException::malformedEntry('SelfRejectingCacheableDiscovery', self::REJECTION);
        }

        return new self($data['value']);
    }
}
