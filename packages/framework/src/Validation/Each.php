<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Attribute;

/**
 * Declares a {@see Constraint} that runs against every element of a
 * `#[ListOf]` field, rather than against the list itself:
 *
 * ```php
 * #[ListOf('string')]
 * #[Each(MinLength::class, 2)]
 * #[Each(Regex::class, pattern: '/^[a-z-]+$/')]
 * public array $tags,
 * ```
 *
 * It is repeatable, and each occurrence names a rule class plus the
 * arguments that rule's own constructor takes — positional or named,
 * kept exactly as written, since that is what a rule is later built
 * from. The rule itself is constructed once per element, per
 * validation, from those literal arguments and discarded, exactly as a
 * field rule is; arguments a rule refuses fail where the rule is first
 * built, as they do for a field rule.
 *
 * This is not itself a `Constraint`: it carries another rule's
 * declaration rather than checking anything. A rule written on the
 * field directly still describes the whole list — `#[MinItems(1)]`
 * counts elements — and those run only once every element has
 * resolved and passed its own `#[Each]` rules.
 *
 * Valid only on a `#[ListOf]` whose elements are scalars or backed
 * enums. A DTO list carries its item class's own rules instead, and a
 * parameter with no `#[ListOf]` has no elements to describe: both are
 * refused where the plan is compiled and where a schema is generated.
 * See Hydrator::listItem().
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class Each
{
    /**
     * @var array<int|string, mixed>
     */
    private array $arguments;

    public function __construct(
        private string $constraint,
        mixed ...$arguments,
    ) {
        $this->arguments = $arguments;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    /**
     * The rule's own constructor arguments as written — positional
     * entries keyed by position, named ones by their parameter name,
     * neither reordered nor reindexed, so `new $class(...$arguments)`
     * reconstructs exactly the declared rule.
     *
     * @return array<int|string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
