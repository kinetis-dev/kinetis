<?php

declare(strict_types=1);

namespace Kinetis\Cache;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<class-string, array<array-key, mixed>> $entries */
        $entries = $data['data'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            data: $entries,
            compiledAt: (string) $data['compiledAt'],
        );
    }
}
