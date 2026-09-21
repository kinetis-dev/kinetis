<?php

declare(strict_types=1);

namespace Kinetis\OpenApi\Exception;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use RuntimeException;

/**
 * A security provider's metadata cannot be published as it stands —
 * raised while the document is generated, so a contradictory or
 * malformed one is never served.
 *
 * It refuses a class named by `#[OpenApiSecurity]` that does not
 * implement {@see SecurityDescriberInterface}; a scheme with no name or
 * a definition that is not an array; a `type` outside OpenAPI 3.1's
 * five; requirements that are not a non-empty list of requirement
 * objects; a requirement naming a scheme its own description does not
 * declare, or holding anything other than a list of scope strings; and
 * two providers defining one scheme name differently, where publishing
 * either definition would misdescribe the other's middleware.
 *
 * Nothing beyond that is checked: a definition's remaining members are
 * the provider's own contract, not this framework's to validate.
 */
final class OpenApiSecurityException extends RuntimeException
{
    public static function notADescriber(string $provider): self
    {
        return new self(
            "#[OpenApiSecurity] names \"{$provider}\", which is not a class implementing "
            . SecurityDescriberInterface::class . '.',
        );
    }

    public static function emptySchemeName(string $provider): self
    {
        return new self(
            "{$provider}::openApiSecurity() declares a security scheme with an empty name. "
            . 'A scheme is published under its name, so it must have one.',
        );
    }

    public static function invalidSchemeDefinition(string $provider, string $scheme): self
    {
        return new self(
            "{$provider}::openApiSecurity() declares security scheme \"{$scheme}\" as something other than an "
            . 'array. A definition is a raw OpenAPI security-scheme object.',
        );
    }

    public static function invalidRequirements(string $provider): self
    {
        return new self(
            "{$provider}::openApiSecurity() declares its requirements as something other than a list of "
            . 'requirement objects. Each one maps a scheme name to that scheme\'s scopes.',
        );
    }

    public static function unsupportedSchemeType(string $provider, string $scheme, string $type): self
    {
        return new self(
            "{$provider}::openApiSecurity() declares security scheme \"{$scheme}\" with type \"{$type}\". "
            . 'OpenAPI 3.1 supports ' . implode(', ', SecurityDescription::TYPES) . '.',
        );
    }

    public static function undeclaredScheme(string $provider, string $scheme): self
    {
        return new self(
            "{$provider}::openApiSecurity() requires security scheme \"{$scheme}\", which the same description "
            . 'does not declare. A requirement may only name a scheme its own description defines.',
        );
    }

    public static function invalidScopes(string $provider, string $scheme): self
    {
        return new self(
            "{$provider}::openApiSecurity() requires security scheme \"{$scheme}\" with something other than a "
            . 'list of scope strings. Use an empty list for a scheme that takes no scopes.',
        );
    }

    public static function noRequirements(string $provider): self
    {
        return new self(
            "{$provider}::openApiSecurity() declares no security requirement. Return at least one requirement "
            . 'object; an empty one is the anonymous alternative.',
        );
    }

    public static function conflictingScheme(string $scheme, string $first, string $second): self
    {
        return new self(
            "{$first} and {$second} both declare security scheme \"{$scheme}\", with different definitions. "
            . 'Give one of them a different scheme name, or make both definitions identical.',
        );
    }
}
