<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;

/**
 * A discovery section only fromArray() can construct, so resolving it
 * from AppScope succeeds only when the discovered instance is bound.
 */
final readonly class PrivateConstructorDiscovery implements CacheableDiscoveryInterface
{
    private function __construct(
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
