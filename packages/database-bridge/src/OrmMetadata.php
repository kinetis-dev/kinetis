<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use LogicException;
use ReflectionClass;

/**
 * kinetis/orm's entity metadata as a discovery section of the AOT cache.
 * Declared via extra.kinetis, so the framework compiles it with the rest of
 * the artifact and binds the reconstructed instance before any package
 * bootstrap runs; PackageBootstrap builds the OrmFactoryRegistry from it.
 *
 * kinetis/orm is optional. Its classes are named here only where naming
 * loads nothing, and class_exists() is checked before any is used, so
 * without the package this class compiles and reconstructs an empty entry.
 */
final readonly class OrmMetadata implements CacheableDiscoveryInterface
{
    private function __construct(private ?MetadataRegistry $registry) {}

    /**
     * Every class under the project's PSR-4 roots and installed packages'
     * scan roots that carries #[Entity], mapped by MetadataRegistry.
     */
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        if (!class_exists(MetadataRegistry::class)) {
            return [];
        }

        $entities = [];

        foreach ([
            ...$context->projectClasses(),
            ...$context->packageClasses(),
        ] as $class) {
            if (new ReflectionClass($class)->getAttributes(Entity::class) !== []) {
                $entities[$class] = true;
            }
        }

        return MetadataRegistry::fromClasses(array_keys($entities))->toArray();
    }

    /**
     * An entry kinetis/orm rejects, or one compiled while the package's
     * presence differed, is reported as a stale artifact, so the framework
     * recompiles instead of booting on it.
     *
     * @throws InvalidCacheArtifactException
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!class_exists(MetadataRegistry::class)) {
            if ($data !== []) {
                throw InvalidCacheArtifactException::malformedEntry(
                    'OrmMetadata',
                    'entity metadata compiled while kinetis/orm was installed',
                );
            }

            return new self(null);
        }

        try {
            return new self(MetadataRegistry::fromArray($data));
        } catch (MappingException $e) {
            throw InvalidCacheArtifactException::malformedEntry('OrmMetadata', $e->getMessage());
        }
    }

    /**
     * @internal Read by PackageBootstrap, which asks only with kinetis/orm installed.
     */
    public function registry(): MetadataRegistry
    {
        return $this->registry ?? throw new LogicException('kinetis/orm is not installed, so there is no entity metadata.');
    }
}
