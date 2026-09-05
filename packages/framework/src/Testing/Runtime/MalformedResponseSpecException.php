<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use InvalidArgumentException;

/**
 * A {@see ResponseSpec} did not survive the boundary it was sent
 * across — a field missing, a field of the wrong type, a body that
 * isn't the base64 it has to be.
 *
 * Thrown rather than defaulted. A spec travels from the test process to
 * a fixture running under a real SAPI, and every assertion the suite
 * then makes about the response is only meaningful if the handler
 * answered with the spec the test wrote. A field quietly replaced by a
 * default would leave the suite asserting confidently against something
 * else entirely.
 */
final class MalformedResponseSpecException extends InvalidArgumentException
{
    public static function missingField(string $field): self
    {
        return new self("The response spec is missing its \"{$field}\" field.");
    }

    public static function wrongType(string $field, string $expected): self
    {
        return new self("The response spec's \"{$field}\" field must be {$expected}.");
    }

    public static function invalidBase64(): self
    {
        return new self('The response spec carries a body that is not valid base64.');
    }
}
