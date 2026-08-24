<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Reflection\AttributeScope;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Mirrors `Kinetis\Events\EventListenerRegistry`: `register()` reflects a
 * class for `#[BroadcastChannel]` methods, validating each one's
 * signature at registration time rather than at the first real request —
 * the same fail-fast discipline `EventListenerRegistry::register()`
 * already applies to `#[Listener]`.
 *
 * Implements `CacheableDiscoveryInterface` — declared as this package's
 * `extra.kinetis` `discovery` class, so the framework itself compiles,
 * caches, and binds an instance of this class before `PackageBootstrap`
 * ever runs. `compile()` is the live-discovery path
 * `BroadcastChannelDiscovery::discover()` already provides, reduced to
 * plain data via `toArray()`.
 */
final class BroadcastChannelRegistry implements CacheableDiscoveryInterface
{
    /** @var list<ChannelDefinition> */
    private array $definitions = [];

    /**
     * @param class-string $class
     */
    public function register(string $class): void
    {
        $reflection = AttributeScope::reflect($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(BroadcastChannel::class);

            if ($attributes === []) {
                continue;
            }

            AttributeScope::assertDeclares($method, $class);

            /** @var BroadcastChannel $attribute */
            $attribute = $attributes[0]->newInstance();

            [$regex, $paramNames] = $this->compilePattern($attribute->pattern);
            $usesCurrentUser = $this->assertSignature($class, $method, $attribute->pattern, $paramNames);

            foreach ($this->definitions as $existing) {
                if ($existing->pattern === $attribute->pattern) {
                    throw InvalidChannelAuthorizerException::duplicatePattern(
                        $attribute->pattern,
                        $existing->class,
                        $existing->method,
                    );
                }
            }

            $this->definitions[] = new ChannelDefinition(
                $attribute->pattern,
                $regex,
                $paramNames,
                $class,
                $method->getName(),
                $usesCurrentUser,
            );
        }
    }

    /**
     * $channelName never carries a `private-`/`presence-` prefix — the
     * caller strips it before matching, since the prefix selects which
     * auth response to build, not which pattern applies.
     */
    public function match(string $channelName): ?ChannelMatch
    {
        foreach ($this->definitions as $definition) {
            if (preg_match('#^' . $definition->regex . '$#', $channelName, $matches) !== 1) {
                continue;
            }

            $params = [];

            foreach ($definition->paramNames as $name) {
                $params[$name] = $matches[$name];
            }

            return new ChannelMatch($definition->class, $definition->method, $definition->usesCurrentUser, $params);
        }

        return null;
    }

    /**
     * The `CacheableDiscoveryInterface` half of the compile path —
     * `fromArray()` below already satisfies the other half as-is, its
     * existing `self` return type being interface-compatible with
     * `static` for this `final` class.
     */
    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return BroadcastChannelDiscovery::discover($projectRoot)->toArray();
    }

    /**
     * @return list<array{pattern: string, regex: string, paramNames: list<string>, class: string, method: string, usesCurrentUser: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ChannelDefinition $definition): array => [
                'pattern' => $definition->pattern,
                'regex' => $definition->regex,
                'paramNames' => $definition->paramNames,
                'class' => $definition->class,
                'method' => $definition->method,
                'usesCurrentUser' => $definition->usesCurrentUser,
            ],
            $this->definitions,
        );
    }

    /**
     * @param list<array{pattern: string, regex: string, paramNames: list<string>, class: string, method: string, usesCurrentUser: bool}> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $registry = new self();

        foreach ($data as $entry) {
            $registry->definitions[] = new ChannelDefinition(
                $entry['pattern'],
                $entry['regex'],
                $entry['paramNames'],
                $entry['class'],
                $entry['method'],
                $entry['usesCurrentUser'],
            );
        }

        return $registry;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function compilePattern(string $pattern): array
    {
        $paramNames = [];
        $regexParts = [];

        $segments = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        assert($segments !== false);

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match) === 1) {
                $paramNames[] = $match[1];
                $regexParts[] = '(?P<' . $match[1] . '>[^.]+)';

                continue;
            }

            $regexParts[] = preg_quote($segment, '#');
        }

        return [implode('', $regexParts), $paramNames];
    }

    /**
     * @param list<string> $paramNames
     * @return bool whether the method's leading parameter is CurrentUserInterface
     */
    private function assertSignature(string $class, ReflectionMethod $method, string $pattern, array $paramNames): bool
    {
        $parameters = $method->getParameters();
        $usesCurrentUser = false;
        $offset = 0;

        if (isset($parameters[0])) {
            $type = $parameters[0]->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === CurrentUserInterface::class) {
                $usesCurrentUser = true;
                $offset = 1;
            }
        }

        $remaining = array_slice($parameters, $offset);

        if (count($remaining) !== count($paramNames)) {
            throw InvalidChannelAuthorizerException::wrongParameterCount(
                $class,
                $method->getName(),
                $pattern,
                count($paramNames),
                count($remaining),
            );
        }

        foreach ($remaining as $index => $parameter) {
            $expectedName = $paramNames[$index];

            if ($parameter->getName() !== $expectedName) {
                throw InvalidChannelAuthorizerException::parameterNameMismatch(
                    $class,
                    $method->getName(),
                    $pattern,
                    $expectedName,
                    $parameter->getName(),
                );
            }

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                throw InvalidChannelAuthorizerException::parameterNotString($class, $method->getName(), $parameter->getName());
            }
        }

        return $usesCurrentUser;
    }
}
