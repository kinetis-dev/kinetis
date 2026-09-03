<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;

/**
 * Every installed package's own `CacheableDiscoveryInterface`-declared
 * data, keyed by the class that produced it (see
 * {@see PackageDiscovery::discoveryClasses()}) — one shared file for
 * every participating package, not one file each: this is typically a
 * handful of small, per-package entries, not the kind of bulk data
 * `HttpCache`'s own three-way split exists to keep a plain request from
 * paying for.
 */
final readonly class PluginCache
{
    private const array TOP_LEVEL_KEYS = ['formatVersion', 'data', 'compiledAt'];

    public function __construct(
        public int $formatVersion,
        /** @var array<class-string, array<array-key, mixed>> */
        public array $data,
        public string $compiledAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'data' => $this->data,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * Validates only that `data` is present and an array — each
     * plugin's own entry is only ever validated by that plugin's own
     * `CacheableDiscoveryInterface::fromArray()`, called separately by
     * `PluginDiscovery::reconstruct()`.
     *
     * @param array<string, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        ArtifactValidation::exactKeys($data, 'PluginCache', self::TOP_LEVEL_KEYS);

        $formatVersion = ArtifactValidation::int($data, 'PluginCache', 'formatVersion');
        $entries = ArtifactValidation::array($data, 'PluginCache', 'data');
        $compiledAt = ArtifactValidation::string($data, 'PluginCache', 'compiledAt');

        /** @var array<class-string, array<array-key, mixed>> $entries */
        return new self(
            formatVersion: $formatVersion,
            data: $entries,
            compiledAt: $compiledAt,
        );
    }
}
