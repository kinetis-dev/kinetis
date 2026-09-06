<?php

declare(strict_types=1);

namespace Kinetis\Reflection;

use Kinetis\Reflection\Exception\UnsupportedDefaultValueException;
use ReflectionParameter;
use UnitEnum;

/**
 * What a derived plan may capture as a parameter's default value,
 * enforced in one place because two derivations depend on the answer
 * being the same: `Kinetis\Validation\Hydrator`'s hydration plan and
 * `Kinetis\Http\Dispatcher`'s HTTP binding plan.
 *
 * A plan is derived once and reused — memoized for a persistent
 * worker's whole lifetime, and written verbatim into
 * `.kinetis-cache/compiled.php` by `kinetis build`. A captured default
 * is therefore the same value on every request that reaches it, which
 * stays faithful to the declaration only for a value PHP would have
 * rebuilt identically each time: scalars, null, arrays of those, and
 * enum cases, which are process-wide singletons carrying no state of
 * their own.
 *
 * Every other object is refused here, where the plan is derived. `new
 * DateTimeImmutable()` as a default reads as "the moment this parameter
 * goes unfilled"; captured into a plan it is one moment — the first
 * request's under a persistent worker, the build's in a compiled
 * artifact — handed to every request after it. Persisting one is a
 * second problem on top: `var_export()` renders an object as a
 * `::__set_state()` call few classes implement, so most such defaults
 * have no artifact to reload at all. Rejecting at derivation is what
 * makes a boot-and-die request, a persistent worker and a build give the
 * same answer, at the same point, in the same words.
 *
 * {@see \Kinetis\Cache\CacheStore} keeps its own check across the whole
 * artifact: it covers every section, including discovery data no
 * parameter default reaches, and names the path to the value it refuses.
 */
final class ParameterDefault
{
    /**
     * $parameter's default value, or null when it declares none — the
     * same pairing every plan writes alongside its own `hasDefault`,
     * which is what tells "defaulted to null" from "no default at all".
     *
     * $owner names the declaration the failure points at: a DTO class
     * for a hydration plan, `Controller::method()` for a binding plan.
     *
     * @throws UnsupportedDefaultValueException
     */
    public static function capture(ReflectionParameter $parameter, string $owner): mixed
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return null;
        }

        /** @var mixed $value */
        $value = $parameter->getDefaultValue();
        self::assertReusable($value, $owner, $parameter->getName());

        return $value;
    }

    /**
     * Recurses into an array default, since a constant expression may
     * build one around a `new` of its own (`[new ArrayObject()]`), and a
     * shared instance nested one level down is shared just as widely.
     */
    private static function assertReusable(mixed $value, string $owner, string $name): void
    {
        if (is_object($value) && !$value instanceof UnitEnum) {
            throw UnsupportedDefaultValueException::forParameter($owner, $name, $value::class);
        }

        if (is_array($value)) {
            /** @var mixed $element */
            foreach ($value as $element) {
                self::assertReusable($element, $owner, $name);
            }
        }
    }
}
