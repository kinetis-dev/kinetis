<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * Matches a value against a delimited PHP PCRE with `preg_match()`.
 *
 * Runtime-only: JSON Schema's `pattern` holds an undelimited ECMA-262
 * expression, a different dialect, so republishing this pattern there
 * would state a rule the request is not checked against.
 *
 * A pattern PCRE cannot compile is a declaration error, refused at
 * construction: left to run, it emits a warning per request and matches
 * nothing, turning every value the field receives into a rule violation
 * a client can never satisfy. The compile is attempted against the
 * empty subject, where `false` can only mean the pattern itself.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Regex implements Constraint
{
    public function __construct(
        private string $pattern,
    ) {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException(
                "Regex pattern \"{$pattern}\" is not a valid PCRE. Include the delimiters, "
                . 'as in /^[a-z]+$/.',
            );
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match($this->pattern, $value) !== 1) {
            return new Violation(
                [],
                'regex',
                "must match the pattern {$this->pattern}.",
                ['pattern' => $this->pattern],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
