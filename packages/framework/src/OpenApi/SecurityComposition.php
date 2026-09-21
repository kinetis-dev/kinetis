<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

use Kinetis\OpenApi\Exception\OpenApiSecurityException;
use stdClass;

/**
 * Composes OpenAPI security requirements and collects the scheme
 * definitions published for them.
 *
 * Providers apply in sequence, which is AND, so composing two of them is
 * the Cartesian product of their alternatives with the scheme maps
 * merged, and a scheme both name keeps the union of the two scope lists
 * (see {@see SecurityDescription} for the requirement shape). Every
 * merged map is canonical — scheme names sorted, each scope list sorted
 * and deduplicated — so alternatives stating the same requirement
 * compare equal and collapse into one.
 *
 * One instance is one document's worth of state. The registry it fills
 * is what that document publishes under `components/securitySchemes`,
 * and it is where two providers defining one scheme name differently is
 * caught, before either definition is published as the other's.
 *
 * A provider is application or satellite code. The container shapes
 * this class iterates are PHPDoc only, so it validates them at runtime
 * before use.
 *
 * This is {@see OpenApiGenerator}'s own machinery, not an extension
 * point. Security is described by implementing
 * {@see SecurityDescriberInterface}.
 *
 * @internal
 *
 * @phpstan-import-type SecurityRequirement from SecurityDescription
 */
final class SecurityComposition
{
    /** @var array<string, array<array-key, mixed>> raw definitions, whose own members are the provider's contract */
    private array $schemes = [];

    /** @var array<string, array<array-key, mixed>> each declared definition with its maps key-sorted, for comparison */
    private array $canonicalSchemes = [];

    /** @var array<string, string> the provider each scheme name was first declared by */
    private array $declaredBy = [];

    /**
     * The security-describing classes of a middleware pipeline, in
     * pipeline order. A middleware describes security only by
     * implementing {@see SecurityDescriberInterface}, which a subclass
     * inherits without redeclaring anything.
     *
     * @param list<class-string> $middleware
     * @return list<class-string>
     */
    public static function describersIn(array $middleware): array
    {
        return array_values(array_filter(
            $middleware,
            static fn (string $class): bool => is_a($class, SecurityDescriberInterface::class, true),
        ));
    }

    /**
     * Turns composed alternatives into what the document carries.
     *
     * A requirement object with no string key — the anonymous
     * alternative, and one naming a scheme PHP holds under an integer
     * key — encodes as a JSON array, which OpenAPI reads as malformed
     * rather than as a requirement. Those travel as objects, so the
     * published document says what was composed.
     *
     * @param list<SecurityRequirement> $alternatives
     * @return list<SecurityRequirement|stdClass>
     */
    public static function publish(array $alternatives): array
    {
        $published = [];

        foreach ($alternatives as $alternative) {
            if (!array_is_list($alternative)) {
                $published[] = $alternative;

                continue;
            }

            $requirement = new stdClass();

            foreach ($alternative as $name => $scopes) {
                $requirement->{(string) $name} = $scopes;
            }

            $published[] = $requirement;
        }

        return $published;
    }

    /**
     * The alternatives that satisfy every provider in $providers, which
     * apply in sequence and therefore all have to be satisfied. An empty
     * $providers composes to the anonymous alternative — the neutral
     * element of the product — so a caller that means "no security at
     * all" publishes an empty list instead of calling this.
     *
     * @param list<string> $providers
     * @return list<SecurityRequirement>
     */
    public function compose(array $providers): array
    {
        $alternatives = [[]];

        foreach ($providers as $provider) {
            $requirements = $this->requirementsOf($provider);
            $combined = [];

            foreach ($alternatives as $left) {
                foreach ($requirements as $right) {
                    $combined[] = self::merge($left, $right);
                }
            }

            $alternatives = self::deduplicate($combined);
        }

        return $alternatives;
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    public function schemes(): array
    {
        return $this->schemes;
    }

    /**
     * Validates $provider's description, registers its schemes, and
     * returns the alternatives it requires.
     *
     * @return list<SecurityRequirement>
     */
    private function requirementsOf(string $provider): array
    {
        if (!is_a($provider, SecurityDescriberInterface::class, true)) {
            throw OpenApiSecurityException::notADescriber($provider);
        }

        $description = $provider::openApiSecurity();
        $requirements = $description->requirements;

        foreach ($description->schemes as $name => $definition) {
            $this->declareScheme($provider, (string) $name, $definition);
        }

        if ($requirements === []) {
            throw OpenApiSecurityException::noRequirements($provider);
        }

        if (!array_is_list($requirements)) {
            throw OpenApiSecurityException::invalidRequirements($provider);
        }

        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                throw OpenApiSecurityException::invalidRequirements($provider);
            }

            foreach ($requirement as $name => $scopes) {
                if (!isset($description->schemes[$name])) {
                    throw OpenApiSecurityException::undeclaredScheme($provider, (string) $name);
                }

                self::assertScopes($provider, (string) $name, $scopes);
            }
        }

        return $requirements;
    }

    /**
     * Registers one scheme definition under its name, or refuses the
     * document when another provider already declared a different
     * definition under that same name. Identical definitions share the
     * one component entry, which is how two routes protected by the same
     * middleware describe one scheme rather than two.
     *
     * $definition is whatever the provider returned under $name.
     */
    private function declareScheme(string $provider, string $name, mixed $definition): void
    {
        if ($name === '') {
            throw OpenApiSecurityException::emptySchemeName($provider);
        }

        if (!is_array($definition)) {
            throw OpenApiSecurityException::invalidSchemeDefinition($provider, $name);
        }

        $type = $definition['type'] ?? null;

        if (!is_string($type) || !in_array($type, SecurityDescription::TYPES, true)) {
            throw OpenApiSecurityException::unsupportedSchemeType(
                $provider,
                $name,
                is_string($type) ? $type : get_debug_type($type),
            );
        }

        $canonical = self::keySorted($definition);
        $declared = $this->canonicalSchemes[$name] ?? null;

        if ($declared !== null && $declared !== $canonical) {
            throw OpenApiSecurityException::conflictingScheme($name, $this->declaredBy[$name], $provider);
        }

        // First declaration wins, so the document publishes a definition
        // as one provider wrote it and the message below names the
        // provider a later one disagrees with.
        $this->schemes[$name] ??= $definition;
        $this->canonicalSchemes[$name] = $canonical;
        $this->declaredBy[$name] ??= $provider;
    }

    /**
     * $definition with every map's keys sorted, at any depth. The order
     * members are written in is not part of what a definition means, so
     * two providers writing one scheme's members in a different order
     * still declare the same scheme. A list's order is its meaning and
     * is left alone.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function keySorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $member) {
            if (is_array($member)) {
                $value[$key] = self::keySorted($member);
            }
        }

        return $value;
    }

    /**
     * $scopes is whatever the provider returned: the declared list of
     * strings is a PHPDoc promise, and this runs on classes outside this
     * framework.
     */
    private static function assertScopes(string $provider, string $name, mixed $scopes): void
    {
        if (!is_array($scopes) || !array_is_list($scopes)) {
            throw OpenApiSecurityException::invalidScopes($provider, $name);
        }

        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                throw OpenApiSecurityException::invalidScopes($provider, $name);
            }
        }
    }

    /**
     * @param SecurityRequirement $left
     * @param SecurityRequirement $right
     * @return SecurityRequirement
     */
    private static function merge(array $left, array $right): array
    {
        foreach ($right as $name => $scopes) {
            $left[$name] = [...$left[$name] ?? [], ...$scopes];
        }

        ksort($left, SORT_STRING);

        foreach ($left as $name => $scopes) {
            $sorted = array_values(array_unique($scopes));
            sort($sorted, SORT_STRING);
            $left[$name] = $sorted;
        }

        return $left;
    }

    /**
     * @param list<SecurityRequirement> $alternatives
     * @return list<SecurityRequirement>
     */
    private static function deduplicate(array $alternatives): array
    {
        $unique = [];

        foreach ($alternatives as $alternative) {
            // Canonical maps, so their encodings are equal exactly when
            // the requirements are.
            $unique[json_encode($alternative, JSON_THROW_ON_ERROR)] = $alternative;
        }

        return array_values($unique);
    }
}
