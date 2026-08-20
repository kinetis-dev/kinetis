<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

/**
 * The adapter refused to produce a response at all — the outcome a
 * driver reports when its environment surfaced an exception instead of
 * a response (Lambda's invocation-error POST, for instance). Distinct
 * from an error *response*, which is a {@see WireResponse} with an error
 * status: the suite asserts which of the two an adapter produces for a
 * given input, since that is exactly where environments tend to
 * diverge.
 *
 * @param class-string<\Throwable> $exceptionClass
 */
final readonly class AdapterRejection
{
    public function __construct(
        public string $exceptionClass,
        public string $message,
    ) {}
}
