<?php

declare(strict_types=1);

namespace Kinetis\Reflection\Exception;

use RuntimeException;

/**
 * A constructor or controller-method parameter declares a default value
 * a derived plan cannot carry: an object that is not an enum case.
 *
 * Thrown from {@see \Kinetis\Reflection\ParameterDefault::capture()},
 * which every plan derivation goes through, so a hydration plan and an
 * HTTP binding plan reject the same declaration in the same words.
 */
final class UnsupportedDefaultValueException extends RuntimeException
{
    public static function forParameter(string $owner, string $parameter, string $class): self
    {
        return new self(
            "Cannot compile a plan for {$owner}, parameter \"\${$parameter}\": its default value holds an "
            . "instance of {$class}. A plan captures a default once and hands that one value to every "
            . 'later request, so only a value PHP would have rebuilt identically each time can be '
            . 'captured: scalars, null, arrays of those, and enum cases. Give the parameter one of those '
            . 'defaults, or make it nullable and construct the object in the constructor body.',
        );
    }
}
