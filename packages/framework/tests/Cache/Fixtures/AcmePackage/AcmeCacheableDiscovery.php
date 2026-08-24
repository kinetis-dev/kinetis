<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\AcmePackage;

use Kinetis\Cache\CacheableDiscoveryInterface;

final readonly class AcmeCacheableDiscovery implements CacheableDiscoveryInterface
{
    public function __construct(
        public string $source,
    ) {}

    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return ['source' => 'from-compile:' . $projectRoot];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self((string) $data['source']);
    }
}
