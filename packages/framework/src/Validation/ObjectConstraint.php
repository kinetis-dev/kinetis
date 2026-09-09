<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * One validation rule about a whole DTO, declared as an attribute on the
 * class it guards (#[AtLeastOneProvided], #[SameAs], ...). It is the
 * cross-field counterpart of {@see Constraint}, which sees one value and
 * cannot express a rule relating two of them.
 *
 * {@see Hydrator} finds these via
 * getAttributes(ObjectConstraint::class, ReflectionAttribute::IS_INSTANCEOF),
 * the same discovery a field rule gets, so adding one never requires
 * touching Hydrator, JsonSchema, or any registry.
 *
 * A rule runs once, after every field has resolved, passed its own
 * rules, and the DTO has been constructed — so it reads a real, fully
 * typed object, never a half-built one. If any field failed, no object
 * rule runs at all: there is no trustworthy DTO for one to describe, and
 * a rule reporting on a value the client never successfully sent would
 * be reporting on the declaration's defaults.
 *
 * Like a field rule, it describes itself twice — {@see validate()} for
 * the failures a request gets back, {@see schema()} for the keywords a
 * published document states — is constructed fresh from the literal
 * `{class, args}` descriptor a plan stores, and is discarded afterwards.
 * It must be pure: no I/O, no container, no request, no global or static
 * mutable state, nothing retained between calls. Throwing, or yielding
 * anything other than a {@see Violation}, is a programmer error and
 * propagates as an ordinary exception — never a client validation
 * response.
 */
interface ObjectConstraint
{
    /**
     * @return iterable<Violation> every way $value broke this rule, or
     *         nothing at all when it did not
     *
     * Returned paths are relative to this DTO — `[]` addresses the
     * object as a whole, `['field']` one of its own members — and
     * {@see Hydrator} prefixes the owning field's or list element's path
     * when the DTO is a nested one, exactly as it does for a field rule.
     */
    public function validate(object $value, ValidationContext $context): iterable;

    /**
     * The DTO constructor field names this rule was configured with —
     * every name it will read off the object or ask
     * {@see ValidationContext} about. A rule about the object as a whole,
     * naming no field, returns `[]`.
     *
     * {@see Hydrator} checks each returned name against the constructor
     * of the class the attribute guards, where the plan is compiled and
     * where a schema is generated — so a mistyped name fails as the
     * definition error it is, instead of a rule silently never matching
     * or a published `anyOf` no request could satisfy.
     *
     * @return list<string>
     */
    public function fields(): array;

    /**
     * The JSON Schema 2020-12 object-level keywords that state this
     * rule, merged into the schema of the DTO it guards. A runtime-only
     * rule — one no keyword expresses, which cross-field rules often are
     * — returns `[]` and leaves the schema untouched.
     *
     * A rule cannot claim a keyword the PHP declaration already owns
     * (`type`, `properties`, `required`, `additionalProperties`) or one
     * another rule on the same class contributes: {@see JsonSchema}
     * refuses that declaration rather than letting one silently
     * overwrite the other.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;
}
