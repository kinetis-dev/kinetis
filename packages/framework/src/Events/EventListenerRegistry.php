<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Events\Exception\InvalidListenerException;
use Kinetis\Reflection\AttributeScope;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reflects every public #[Listener] method on a class. Holds plain data
 * only (class-string, method name, priority), never a live container
 * reference: EventDispatcher is the piece with container access,
 * resolving a listener instance only once it actually needs to invoke
 * one.
 *
 * Exact event-class matching only, deliberately — a listener for
 * OrderPlaced doesn't also fire for a subclass. Adding interface/parent-
 * class-based matching later is a lookup-strategy change inside this
 * class, not an API change.
 *
 * Each event's own listener list is re-sorted (priority descending, ties
 * broken alphabetically by class then method name) immediately after
 * every register() call that adds to it — cheap, since this only ever
 * runs at discovery/registration time against a short list, never on the
 * hot per-dispatch path EventDispatcher::listenersFor() reads from.
 *
 * Unlike Router/McpRegistry/CommandRegistry, an instance of this class
 * must be registered via $app->instance(EventListenerRegistry::class, ...)
 * before AppScope::boot() locks bindings — EventDispatcher constructor-
 * injects it and is itself autowired through the container, so it needs
 * an explicit binding to resolve from; the other three are handed
 * directly to whatever dispatches them and never touch AppScope at all.
 */
final class EventListenerRegistry
{
    /** @var array<class-string, list<array{class: class-string, method: string, priority: int}>> */
    private array $listeners = [];

    /**
     * @param class-string $class
     * @throws InvalidListenerException
     */
    public function register(string $class): void
    {
        $reflection = AttributeScope::reflect($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Listener::class);

            if ($attributes === []) {
                continue;
            }

            AttributeScope::assertDeclares($method, $class);

            $parameters = $method->getParameters();

            if (count($parameters) !== 1) {
                throw InvalidListenerException::forMethod($class, $method->getName());
            }

            $type = $parameters[0]->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw InvalidListenerException::forMethod($class, $method->getName());
            }

            /** @var class-string $eventClass */
            $eventClass = $type->getName();
            $priority = $attributes[0]->newInstance()->priority;

            $this->listeners[$eventClass][] = ['class' => $class, 'method' => $method->getName(), 'priority' => $priority];

            usort(
                $this->listeners[$eventClass],
                static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']
                    ?: $a['class'] <=> $b['class']
                    ?: $a['method'] <=> $b['method'],
            );
        }
    }

    /**
     * @param class-string $eventClass
     * @return list<array{class: class-string, method: string, priority: int}>
     */
    public function listenersFor(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }

    /**
     * @return array<class-string, list<array{class: class-string, method: string, priority: int}>>
     */
    public function toArray(): array
    {
        return $this->listeners;
    }

    /**
     * Reconstructs a registry from toArray()'s output with zero
     * reflection. The listener lists are already sorted, so this trusts
     * that ordering rather than re-sorting.
     *
     * @param array<class-string, list<array{class: class-string, method: string, priority: int}>> $listeners
     */
    public static function fromArray(array $listeners): self
    {
        $registry = new self();
        $registry->listeners = $listeners;

        return $registry;
    }
}
