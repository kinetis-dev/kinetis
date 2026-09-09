<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use Kinetis\Validation\Hydrator;
use RuntimeException;

/**
 * A DTO declares something Kinetis\Validation\Hydrator cannot honour:
 * a constructor parameter shape it does not hydrate, or a class-level
 * object rule that does not hold up its own contract.
 *
 * A parameter-shape failure is raised while the hydration plan is
 * compiled — at build time for an AOT-compiled plan, or on the first
 * hydrate() call for a live one — so the definition fails as a
 * definition, never as a raw TypeError on a real request. An object rule
 * naming a field the DTO's constructor does not declare fails there too,
 * and in schema generation, because both read the rules through
 * Hydrator::collectObjectRules(). The remaining two object-rule failures
 * — an unreadable field, a yielded non-Violation — become knowable only
 * when the rule runs against a constructed DTO. All of them are defects
 * in the rule or its declaration, so none is ever converted into a
 * client validation response.
 *
 * See Hydrator's own docblock for the complete set of parameter shapes
 * a plan accepts.
 */
final class UnsupportedDtoDefinitionException extends RuntimeException
{
    public static function compositeType(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            'Kinetis hydrates no intersection type, and no union but the T|Absent and T|null|Absent '
            . 'presence forms. Declare a single named type, or one of those.',
        );
    }

    public static function recursiveDefinition(string $class, string $parameter, string $repeated): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "it references {$repeated}, which is already being compiled in this chain. A hydration plan "
            . 'embeds every nested class inline, so a recursive definition has no finite plan.',
        );
    }

    public static function unresolvableClass(string $class, string $parameter, string $type): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "\"{$type}\" does not resolve to a class or interface. Name the class explicitly rather than "
            . 'self, parent or static.',
        );
    }

    public static function listOfOnNonArrayParameter(string $class, string $parameter): self
    {
        return self::forParameter($class, $parameter, '#[ListOf] only applies to a parameter typed array.');
    }

    /**
     * A #[ListOf] naming something no element could ever be: an empty
     * name, a builtin with no element vocabulary (`array`, `iterable`,
     * `mixed`, ...), a name no class answers to, or a class nothing on
     * the wire can produce — an interface, an abstract class, a unit
     * enum. `Psr\Http\Message\UploadedFileInterface` falls here too: a
     * list of uploads has no wire representation Kinetis hydrates.
     */
    public static function unsupportedListItemType(string $class, string $parameter, string $itemType): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "#[ListOf(\"{$itemType}\")] names a type no element can have. A list item is string, int, "
            . 'float, bool, a backed enum, or a class that can be instantiated.',
        );
    }

    /**
     * A backed enum with no cases, named by a DTO field or a #[ListOf].
     * Legal PHP, and useless as an input domain: no value matches a
     * case that does not exist, and JSON Schema's `enum` may not be
     * empty, so such a field could only publish a schema it rejects
     * every request against.
     */
    public static function emptyBackedEnum(string $class, string $parameter, string $enum): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "{$enum} is a backed enum with no cases, so no value could ever name one of them.",
        );
    }

    public static function eachWithoutListOf(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            '#[Each] states a rule about every element of a list, and this parameter declares no #[ListOf]. '
            . 'Declare the list, or write the rule on the field itself.',
        );
    }

    public static function eachOnDtoList(string $class, string $parameter, string $itemClass): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "#[Each] applies to scalar and backed-enum elements, and this list holds {$itemClass} objects. "
            . 'Declare the rule on the field of that class it describes.',
        );
    }

    public static function eachNotAConstraint(string $class, string $parameter, string $constraint): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "#[Each(\"{$constraint}\")] does not name a Kinetis\\Validation\\Constraint implementation, "
            . 'so nothing could be asked of an element.',
        );
    }

    public static function objectMapOnNonArrayParameter(string $class, string $parameter): self
    {
        return self::forParameter($class, $parameter, '#[ObjectMap] only applies to a parameter typed array.');
    }

    public static function objectMapWithListOf(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            '#[ObjectMap] admits a JSON object and #[ListOf] a JSON array, so a parameter carrying both '
            . 'accepts nothing. Declare one of them.',
        );
    }

    public static function unsupportedBuiltinType(string $class, string $parameter, string $type): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "\"{$type}\" is not a builtin type a request value can be bound to. Kinetis accepts "
            . implode(', ', Hydrator::SUPPORTED_BUILTIN_TYPES) . ', or a class type.',
        );
    }

    /**
     * A union type on a DTO constructor parameter that is not one of the
     * two `Absent` presence forms. Named for what it actually is —
     * `compositeType()` above still answers every other union and every
     * intersection — so the message can point at the shape that would
     * have worked.
     */
    public static function absentUnionWithoutValueType(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            'a union with Kinetis\\Validation\\Absent needs exactly one value type beside it. Declare '
            . 'T|Absent or T|null|Absent, where T is the type a supplied value has.',
        );
    }

    public static function absentUnionWithMultipleValueTypes(string $class, string $parameter, string $types): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "a union with Kinetis\\Validation\\Absent carries exactly one value type, and this one names "
            . "{$types}. A supplied value has one type; only its presence is the union.",
        );
    }

    public static function absentUnionWithoutDefault(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            'a union with Kinetis\\Validation\\Absent must default to Absent::Value. Nothing else can '
            . 'produce the marker — no input may — so a defaultless one could never be filled at all.',
        );
    }

    public static function absentUnionWithWrongDefault(string $class, string $parameter, string $default): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "a union with Kinetis\\Validation\\Absent must default to exactly Absent::Value, not "
            . "{$default}. The default is what an omitted member binds, and omission is what the marker means.",
        );
    }

    /**
     * An {@see \Kinetis\Validation\ObjectConstraint} yielded something
     * that is not a Violation. The contract is the rule's own, so this
     * is a defect in the rule, reported as the definition failure it is
     * rather than reaching a client as a validation response.
     */
    public static function objectRuleYieldedNonViolation(string $class, string $rule, string $given): self
    {
        return new self(
            "Object rule \"{$rule}\" on {$class} yielded {$given}. An object rule yields "
            . 'Kinetis\\Validation\\Violation instances and nothing else.',
        );
    }

    /**
     * An object rule named a field the DTO's constructor does not
     * declare. Raised where the rules are collected, so it stops a
     * hydration plan and a generated schema alike: a rule naming a field
     * that cannot exist would either never match or publish a keyword no
     * request could satisfy.
     */
    public static function objectRuleUnknownField(string $class, string $rule, string $field): self
    {
        return new self(
            "Object rule \"{$rule}\" on {$class} names field \"{$field}\", which is not a constructor "
            . 'parameter of that class. An object rule names the fields it relates, and a name no field '
            . 'answers to states a rule the class cannot have.',
        );
    }

    /**
     * An object rule named a field it cannot read off the constructed
     * DTO. The rule's arguments name properties of the class it guards,
     * so this is a mistake in the attribute, never in the request.
     */
    public static function objectRuleUnreadableField(string $class, string $rule, string $field, string $reason): self
    {
        return new self(
            "Object rule \"{$rule}\" on {$class} cannot read field \"{$field}\": {$reason}. Name a "
            . 'constructor field of that class.',
        );
    }

    public static function notInstantiable(string $class): self
    {
        return new self(
            "Cannot compile a hydration plan for {$class}: it cannot be instantiated. Hydration builds the "
            . 'class itself, so an interface, abstract class or enum can only be supplied as an existing '
            . 'instance, never hydrated as a DTO.',
        );
    }

    private static function forParameter(string $class, string $parameter, string $reason): self
    {
        return new self("Cannot hydrate {$class}::\${$parameter}: {$reason}");
    }
}
