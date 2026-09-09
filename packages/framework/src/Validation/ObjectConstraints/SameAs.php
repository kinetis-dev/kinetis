<?php

declare(strict_types=1);

namespace Kinetis\Validation\ObjectConstraints;

use Attribute;
use InvalidArgumentException;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\ObjectConstraint;
use Kinetis\Validation\ValidationContext;
use Kinetis\Validation\Violation;
use ReflectionClass;

/**
 * Two of the DTO's own fields must hold the identical value — the
 * password-confirmation rule, and every other "type it twice" pair.
 *
 * The comparison itself runs only when the input supplied both members.
 * Whether either of them had to be there at all is a separate question,
 * answered by the PHP declaration (a defaultless parameter is required)
 * or by another rule such as {@see AtLeastOneProvided} — so a
 * partially-filled form reports the missing member once, as missing,
 * instead of also reporting a mismatch against a default the client
 * never sent.
 *
 * The values are read straight off the constructed object by reflection,
 * so a promoted `private`/`protected` readonly field — the ordinary
 * shape of a DTO with asymmetric visibility — is readable without the
 * class exposing a getter and without anything bypassing its
 * constructor. Nothing reflective is retained: the lookup happens inside
 * the one call and is discarded with the rule instance.
 *
 * A misnamed field is a mistake in the attribute rather than in the
 * request, and fails as one in both places it can be caught: a name the
 * DTO's constructor does not declare stops the hydration plan and the
 * generated schema (see {@see fields()}), and a constructor field the
 * object holds no readable value for throws an
 * {@see UnsupportedDtoDefinitionException} on the first hydration that
 * runs the rule. Neither is ever a violation a client is shown.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class SameAs implements ObjectConstraint
{
    public function __construct(
        private string $field,
        private string $other,
    ) {
        if ($field === '' || $other === '') {
            throw new InvalidArgumentException('SameAs field names must not be empty.');
        }

        if ($field === $other) {
            throw new InvalidArgumentException(
                "SameAs compares two different fields, and was given \"{$field}\" twice.",
            );
        }
    }

    /**
     * Both names this rule compares, so
     * {@see \Kinetis\Validation\Hydrator} rejects one the DTO's
     * constructor does not declare where the plan is compiled. Whether
     * each one is also a readable property of the constructed object is
     * this rule's own check, in {@see read()}.
     *
     * @return list<string>
     */
    #[\Override]
    public function fields(): array
    {
        return [$this->field, $this->other];
    }

    #[\Override]
    public function validate(object $value, ValidationContext $context): iterable
    {
        // Both reads happen before presence is consulted: a name the
        // class holds no value for is a defect in the declaration, and a
        // request omitting either field must not be what decides whether
        // that defect is noticed.
        $confirming = self::read($value, $this->field);
        $confirmed = self::read($value, $this->other);

        if (!$context->wasSupplied($this->field) || !$context->wasSupplied($this->other)) {
            return;
        }

        if ($confirming === $confirmed) {
            return;
        }

        // Reported on the confirming field, the one the client is asked
        // to retype — the other is the value being confirmed.
        yield new Violation(
            [$this->field],
            'same_as',
            "must match {$this->other}.",
            ['other' => $this->other],
        );
    }

    /**
     * No JSON Schema keyword compares one property's value with
     * another's, so this rule is enforced at runtime only and publishes
     * nothing rather than publishing something weaker that a client
     * could satisfy and still be rejected.
     */
    #[\Override]
    public function schema(): array
    {
        return [];
    }

    private static function read(object $value, string $field): mixed
    {
        $reflection = new ReflectionClass($value);

        if (!$reflection->hasProperty($field)) {
            throw UnsupportedDtoDefinitionException::objectRuleUnreadableField(
                $value::class,
                self::class,
                $field,
                'the class declares no such property',
            );
        }

        $property = $reflection->getProperty($field);

        if ($property->isStatic()) {
            throw UnsupportedDtoDefinitionException::objectRuleUnreadableField(
                $value::class,
                self::class,
                $field,
                'it is a static property, which holds no per-object value',
            );
        }

        if (!$property->isInitialized($value)) {
            throw UnsupportedDtoDefinitionException::objectRuleUnreadableField(
                $value::class,
                self::class,
                $field,
                'it holds no value on the constructed object',
            );
        }

        return $property->getValue($value);
    }
}
