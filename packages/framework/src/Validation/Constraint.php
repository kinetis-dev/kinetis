<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * One validation rule, declared as an attribute on the constructor
 * parameter it guards (#[Email], #[MinLength], ...). Hydrator finds them
 * via getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF)
 * the same way Router finds RouteAttribute instances, so adding a rule
 * never requires touching Hydrator, JsonSchema, or any registry.
 *
 * A rule describes itself twice, and the two descriptions are the same
 * rule seen from either end:
 *
 * - {@see validate()} answers what a value that broke the rule gets
 *   back — the rule's own stable code, its default-English sentence, and
 *   the values that sentence was built from.
 * - {@see schema()} answers which JSON Schema 2020-12 keywords state the
 *   same rule to a client generating requests from the published
 *   document, so what the schema promises and what the request is
 *   actually checked against cannot drift apart.
 *
 * A rule is constructed fresh for each validation or schema operation,
 * from the literal `{class, args}` descriptor a plan stores, and
 * discarded — so it must be pure: no I/O, no container, no request, no
 * global or static mutable state, nothing retained between calls.
 * Throwing is a programmer error, not a client violation, and propagates
 * as an ordinary exception.
 */
interface Constraint
{
    /**
     * @return Violation|null null when $value satisfies the rule
     *
     * The returned path is relative to the value being checked, so it is
     * normally `[]`: the rule knows what went wrong, never where the
     * value lives. {@see Hydrator} prefixes the owning field's or
     * parameter's own path before the violation reaches a transport.
     */
    public function validate(mixed $value): ?Violation;

    /**
     * The JSON Schema 2020-12 keywords that state this rule, merged into
     * the schema of whatever the rule guards. A runtime-only rule — one
     * no keyword expresses — returns `[]` and leaves the schema
     * untouched.
     *
     * Two rules on the same target must not contribute the same keyword:
     * {@see JsonSchema} refuses that declaration rather than letting one
     * silently overwrite the other.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;
}
