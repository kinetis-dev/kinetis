<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Reflection\AttributeScope;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Mirrors `Kinetis\Events\EventListenerRegistry`: `register()` reflects a
 * class for `#[BroadcastChannel]` methods, validating each one's pattern
 * and signature at registration time rather than at the first real
 * request.
 *
 * Implements `CacheableDiscoveryInterface` — declared as this package's
 * `extra.kinetis` `discovery` class, so the framework compiles, caches,
 * and binds an instance of this class before `PackageBootstrap` runs.
 * `compile()` is the live-discovery path `BroadcastChannelDiscovery`
 * already provides, reduced to plain data by `toArray()`.
 *
 * Registration is append-only into a fresh registry, and every overlap
 * is a conflict — see {@see ChannelDefinition::overlaps()}. At most one
 * authorizer therefore claims a channel name, so `match()` carries no
 * precedence, ordering, or fallthrough of its own, and an application
 * needing a special case for one name branches on it inside a single
 * authorizer or uses disjoint channel namespaces.
 */
final class BroadcastChannelRegistry implements CacheableDiscoveryInterface
{
    private const array CHANNEL_ENTRY_KEYS = ['pattern', 'class', 'method', 'usesCurrentUser'];

    private const string ARTIFACT_COMPONENT = 'BroadcastChannelRegistry channel';

    /** @var list<ChannelDefinition> */
    private array $definitions = [];

    /**
     * Adds every `#[BroadcastChannel]` method $class declares. Discovery
     * calls this once per class; a second call conflicts with the
     * definitions the first added, the same as any repeated pattern.
     *
     * @param class-string $class
     * @throws InvalidChannelAuthorizerException
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

            $definition = ChannelDefinition::fromPattern(
                $attribute->pattern,
                $class,
                $method->getName(),
                self::declaresCurrentUser($method),
            );

            self::assertSignature($method, $definition);
            $this->add($definition);
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
            $params = $definition->extract($channelName);

            if ($params !== null) {
                return new ChannelMatch($definition->class, $definition->method, $definition->usesCurrentUser, $params);
            }
        }

        return null;
    }

    /**
     * The `CacheableDiscoveryInterface` half of the compile path —
     * `fromArray()` below satisfies the other half, its `self` return
     * type being interface-compatible with `static` for this `final`
     * class.
     */
    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return BroadcastChannelDiscovery::discover($projectRoot)->toArray();
    }

    /**
     * @return list<array{pattern: string, class: string, method: string, usesCurrentUser: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ChannelDefinition $definition): array => [
                'pattern' => $definition->pattern,
                'class' => $definition->class,
                'method' => $definition->method,
                'usesCurrentUser' => $definition->usesCurrentUser,
            ],
            $this->definitions,
        );
    }

    /**
     * Checks each entry's exact four fields via
     * `Kinetis\Cache\Exception\ArtifactValidation`, rebuilds the
     * definition from `pattern` alone, and runs it through the same
     * `add()` a live registration uses, so both paths reject the same
     * patterns. The registration exception is reclassified once here:
     * a malformed artifact must raise
     * `Kinetis\Cache\Exception\InvalidCacheArtifactException`, the one
     * category `BootSequence` recompiles on, rather than a `TypeError`
     * or a live-registration exception escaping boot.
     *
     * @param array<array-key, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!array_is_list($data)) {
            throw InvalidCacheArtifactException::wrongFieldType('BroadcastChannelRegistry', '(root)', 'a list');
        }

        $registry = new self();

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw InvalidCacheArtifactException::malformedEntry('BroadcastChannelRegistry', 'a non-array entry');
            }

            ArtifactValidation::exactKeys($entry, self::ARTIFACT_COMPONENT, self::CHANNEL_ENTRY_KEYS);

            $pattern = ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'pattern');

            try {
                $registry->add(ChannelDefinition::fromPattern(
                    $pattern,
                    ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'class'),
                    ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'method'),
                    ArtifactValidation::bool($entry, self::ARTIFACT_COMPONENT, 'usesCurrentUser'),
                ));
            } catch (InvalidChannelAuthorizerException $e) {
                throw InvalidCacheArtifactException::malformedEntry(
                    'BroadcastChannelRegistry',
                    "pattern \"{$pattern}\" is not registrable: {$e->getMessage()}",
                );
            }
        }

        return $registry;
    }

    /**
     * The one place a definition enters the registry, shared by live
     * registration and artifact hydration.
     */
    private function add(ChannelDefinition $definition): void
    {
        foreach ($this->definitions as $existing) {
            if ($existing->overlaps($definition)) {
                throw InvalidChannelAuthorizerException::overlappingPattern(
                    $definition->pattern,
                    $existing->pattern,
                    $existing->class,
                    $existing->method,
                );
            }
        }

        $this->definitions[] = $definition;
    }

    /**
     * Whether the method opts into the request's identity by declaring
     * `CurrentUserInterface` first. Omitting it lets the method
     * authorize from its own context, an anonymous request included.
     */
    private static function declaresCurrentUser(ReflectionMethod $method): bool
    {
        $first = $method->getParameters()[0] ?? null;

        if ($first === null) {
            return false;
        }

        $type = $first->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === CurrentUserInterface::class;
    }

    /**
     * Past the optional leading `CurrentUserInterface`, the remaining
     * parameters must be one `string` per placeholder, named and ordered
     * to match the pattern.
     */
    private static function assertSignature(ReflectionMethod $method, ChannelDefinition $definition): void
    {
        $remaining = array_slice($method->getParameters(), $definition->usesCurrentUser ? 1 : 0);

        if (count($remaining) !== count($definition->captureNames)) {
            throw InvalidChannelAuthorizerException::wrongParameterCount(
                $definition->class,
                $definition->method,
                $definition->pattern,
                count($definition->captureNames),
                count($remaining),
            );
        }

        foreach ($remaining as $index => $parameter) {
            $expectedName = $definition->captureNames[$index];

            if ($parameter->getName() !== $expectedName) {
                throw InvalidChannelAuthorizerException::parameterNameMismatch(
                    $definition->class,
                    $definition->method,
                    $definition->pattern,
                    $expectedName,
                    $parameter->getName(),
                );
            }

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                throw InvalidChannelAuthorizerException::parameterNotString(
                    $definition->class,
                    $definition->method,
                    $parameter->getName(),
                );
            }
        }
    }
}
