<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use InvalidArgumentException;

/**
 * An {@see ObservedRequest} did not survive the boundary it was written
 * across — a field missing, a field of the wrong type, contents that are
 * not the base64 they have to be.
 *
 * Thrown rather than coerced. A fixture running under a real SAPI writes
 * the observation and the test process reads it back, and every
 * request-side assertion the conformance suite makes is made against
 * what comes out of that read. A cast would turn a field that arrived
 * wrong into a plausible value and a lenient base64 decode would turn a
 * body that did not survive into an empty one — either reports a broken
 * fixture or driver as a passing conformance run.
 */
final class MalformedObservationException extends InvalidArgumentException
{
    public static function missingField(string $field): self
    {
        return new self("The observed request is missing its \"{$field}\" field.");
    }

    public static function wrongType(string $field, string $expected): self
    {
        return new self("The observed request's \"{$field}\" field must be {$expected}.");
    }

    public static function invalidBase64(): self
    {
        return new self('The observed request carries a value that is not valid base64.');
    }
}
