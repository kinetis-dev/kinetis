<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\AcmePackage;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

final readonly class AcmeCacheableDiscovery implements CacheableDiscoveryInterface
{
    public function __construct(
        public string $source,
    ) {}

    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        return ['source' => 'from-compile:' . $context->projectRoot];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self((string) $data['source']);
    }
}
