<?php

declare(strict_types=1);

namespace Kinetis\Orm\Linting;

use Kinetis\Orm\Attributes\Id;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * A generated identifier is written by the ORM, not by the entity: the
 * property holds null until the insert commits, and `flush()` then assigns
 * the key the database reported through reflection. PHPStan sees only the
 * `= null` default, so `TooWidePropertyTypeRule` reports
 * `property.unusedType` for the required `?int`, and
 * `UnusedPrivatePropertyRule` reports the property as never written. Both
 * ask this extension first, and it answers that the ORM writes it.
 *
 * The admitted case is exactly `#[Id(generated: true)]`. An assigned
 * identifier is written by the application, so PHPStan's reports about it
 * are correct and stay.
 *
 * Ships under the main autoload, like the framework's linting rules,
 * because it is meant to run against a consumer's entities: their
 * `phpstan.neon` includes this package's `extension.neon`, which
 * `autoload-dev` could never reach. Nothing at runtime references this
 * class, so a production install without PHPStan never autoloads it.
 */
final class GeneratedIdentifierPropertyExtension implements ReadWritePropertiesExtension
{
    #[\Override]
    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    #[\Override]
    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getName() !== Id::class) {
                continue;
            }

            // Absent for #[Id], which defaults $generated to false; PHPStan
            // keys a positional argument by its parameter name too.
            $generated = $attribute->getArgumentTypes()['generated'] ?? null;

            return $generated !== null && $generated->isTrue()->yes();
        }

        return false;
    }

    #[\Override]
    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }
}
