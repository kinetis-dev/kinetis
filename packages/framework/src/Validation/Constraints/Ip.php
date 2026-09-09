<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An IP address in either family, as PHP's own FILTER_VALIDATE_IP reads
 * one: a dotted-quad IPv4 or an IPv6 address, and nothing around it.
 * A zone identifier, a CIDR prefix, bracket notation and surrounding
 * whitespace are all rejected, because none of them is an address.
 *
 * The two families are published as the union of their own formats
 * rather than a single one: JSON Schema has no combined `ip` format, and
 * naming one family would state a rule the other half of the accepted
 * values does not satisfy.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Ip implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return new Violation([], 'ip', 'must be a valid IP address.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]];
    }
}
