<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

/**
 * One provider's OpenAPI security metadata: the schemes it defines, and
 * what a request must satisfy to pass it.
 *
 * `$requirements` is disjunctive normal form. The outer list is OR — a
 * request satisfying any one entry passes — and the schemes named inside
 * one entry are AND. Each value is the scopes that scheme is required
 * with, empty for a scheme that has none. An entry naming no scheme at
 * all is the anonymous alternative: the provider states that it admits a
 * request carrying no credential.
 *
 * `$schemes` are raw OpenAPI 3.1 security-scheme objects, published
 * under `components/securitySchemes` as given. Generation checks that
 * each scheme definition and requirement entry is an array, that the
 * requirements form a list, the scheme name and `type`, that every
 * requirement names a scheme this description declares, and that two
 * providers sharing a scheme name define it identically; the rest of a
 * definition is the provider's own contract.
 *
 * @phpstan-type SecurityRequirement array<string, list<string>>
 */
final readonly class SecurityDescription
{
    /** The scheme types OpenAPI 3.1 defines; a definition's `type` names one of them. */
    public const array TYPES = ['apiKey', 'http', 'mutualTLS', 'oauth2', 'openIdConnect'];

    /**
     * @param array<string, array<string, mixed>> $schemes
     * @param list<SecurityRequirement> $requirements
     */
    public function __construct(
        public array $schemes,
        public array $requirements,
    ) {}

    /**
     * The usual description: one scheme, required on its own.
     *
     * @param array<string, mixed> $definition
     * @param list<string> $scopes
     */
    public static function scheme(string $name, array $definition, array $scopes = []): self
    {
        return new self([$name => $definition], [[$name => $scopes]]);
    }
}
