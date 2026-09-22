<?php

declare(strict_types=1);

namespace Kinetis\Orm\Linting;

use Kinetis\Orm\Attributes\Entity;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * `MetadataRegistry::entity()` maps every non-static property of an
 * `#[Entity]` class. The ORM accesses each one through reflection: it
 * reads a column's and a relationship owner's value while planning and
 * flushing, and it writes a hydrated column, a generated identifier, and
 * a loaded relationship. PHPStan sees none of that hidden use, so it
 * reports a plain column or a relationship owner the application only
 * assigns as `property.onlyWritten`. `isAlwaysRead()` is the only
 * extension answer that clears that false positive, so this extension
 * answers that every mapped property is read.
 *
 * `TooWidePropertyTypeRule` drops a property as soon as an extension
 * answers `isAlwaysRead()`, so a mapped property is exempt from
 * `property.unusedType` too. PHPStan offers no narrower answer.
 *
 * Ships under the main autoload, like the framework's linting rules,
 * because it is meant to run against a consumer's entities: their
 * `phpstan.neon` includes this package's `extension.neon`, which
 * `autoload-dev` could never reach. Nothing at runtime references this
 * class, so a production install without PHPStan never autoloads it.
 */
final class EntityPropertyExtension implements ReadWritePropertiesExtension
{
    #[\Override]
    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        if ($property->isStatic()) {
            return false;
        }

        foreach ($property->getDeclaringClass()->getAttributes() as $attribute) {
            if ($attribute->getName() === Entity::class) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    #[\Override]
    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }
}
