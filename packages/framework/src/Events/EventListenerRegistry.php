<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Events\Exception\InvalidListenerException;
use Kinetis\Reflection\AttributeScope;
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
 * Each entry also records whether its class implements ShouldQueue
 * (`queued`), computed once here via is_a(), not re-derived from a live
 * instance at dispatch time — EventDispatcher reads this flag to decide
 * whether to construct the listener at all before ever calling get() on
 * it, so a queued listener's own constructor never runs in the producer
 * process.
 *
 * register() is idempotent per class: a class already registered is a
 * safe no-op on a second call, tracked via $registeredClasses rather than
 * re-derived by scanning $listeners. This is the invariant every
 * discovery source (a project's own scan, the framework segment,
 * installed-package roots) relies on instead of keeping its own
 * deduplication bookkeeping — EventListenerDiscovery feeds every source
 * to this class directly now. Registration is also atomic: every
 * attributed method on a class is validated before any of them are
 * appended to $listeners or the class is marked registered, so a class
 * with one invalid #[Listener] method registers none of its methods, not
 * just the ones reflected before the failure — and, since the class is
 * never marked registered on a failed attempt, retrying register() on
 * the same still-invalid class throws again, every time, rather than
 * silently becoming a no-op.
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
    /** A plain PHP identifier — used for a method name. */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

    /** A backslash-separated sequence of identifiers — used for a class-string. */
    private const string CLASS_STRING_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    /** @var array<class-string, list<array{class: class-string, method: string, priority: int, queued: bool}>> */
    private array $listeners = [];

    /** @var array<class-string, true> */
    private array $registeredClasses = [];

    /**
     * @param class-string $class
     * @throws InvalidListenerException
     */
    public function register(string $class): void
    {
        if (isset($this->registeredClasses[$class])) {
            return;
        }

        $reflection = AttributeScope::reflect($class);
        $queued = is_a($class, ShouldQueue::class, true);

        /** @var list<array{eventClass: class-string, entry: array{class: class-string, method: string, priority: int, queued: bool}}> $pending */
        $pending = [];

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

            $pending[] = [
                'eventClass' => $eventClass,
                'entry' => ['class' => $class, 'method' => $method->getName(), 'priority' => $priority, 'queued' => $queued],
            ];
        }

        // Nothing is appended, and $class is not marked registered, until
        // every attributed method has been validated successfully above —
        // a thrown InvalidListenerException leaves $listeners/
        // $registeredClasses exactly as they were before this call, so a
        // later register() call for the same still-invalid class reaches
        // this same reflection path again rather than short-circuiting.
        foreach ($pending as $item) {
            $this->listeners[$item['eventClass']][] = $item['entry'];

            usort(
                $this->listeners[$item['eventClass']],
                static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']
                    ?: $a['class'] <=> $b['class']
                    ?: $a['method'] <=> $b['method'],
            );
        }

        $this->registeredClasses[$class] = true;
    }

    /**
     * @param class-string $eventClass
     * @return list<array{class: class-string, method: string, priority: int, queued: bool}>
     */
    public function listenersFor(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }

    /**
     * @return array<class-string, list<array{class: class-string, method: string, priority: int, queued: bool}>>
     */
    public function toArray(): array
    {
        return $this->listeners;
    }

    /**
     * Reconstructs a registry from toArray()'s output with zero
     * reflection — but never by trusting the given data's runtime shape,
     * since a matching CacheFormat::VERSION doesn't by itself rule out
     * hand-edited or otherwise corrupt content:
     *
     * - Every event key must be a real string (PHP silently coerces a
     *   numeric-looking array key to int, the same footgun
     *   Kinetis\Queue\Support\WireValue's own key handling already guards
     *   against elsewhere in this codebase) shaped like a valid
     *   class-string.
     * - Every event's own value must be a dense list — never a scalar, an
     *   associative array, or a sparse one — checked explicitly rather
     *   than left to produce a foreach warning or silently iterate
     *   nothing.
     * - Every entry must have exactly the four required keys (class,
     *   method, priority, queued) — no fewer, no more — each correctly
     *   typed, with class/method further constrained to a syntactically
     *   valid class-string/identifier shape.
     * - No two entries for the same event may name the same
     *   {class, method} pair, whether identical in every other field or
     *   genuinely conflicting (a different priority or queued value) —
     *   Kinetis is not deployed anywhere with an existing compiled
     *   generation to preserve, so there is no legacy duplicate this
     *   class needs to tolerate or silently resolve; any duplicate is
     *   corruption and is rejected outright.
     *
     * Every event's own list is re-sorted here by the identical
     * priority-desc/class/method comparator register() itself uses,
     * rather than trusting whatever order the input happens to already
     * be in — canonicalizing unconditionally is simpler, and no more
     * expensive, than verifying an arbitrary input is already correctly
     * sorted, for the short lists this class ever holds.
     *
     * Throws loudly rather than trusting arbitrary data, but provides no
     * fallback or recovery path of its own: nothing in the production
     * cache-loading pipeline currently catches this exception, so a
     * malformed generation is a hard failure at boot, not a silent
     * recompile.
     *
     * @param array<class-string, list<array{class: class-string, method: string, priority: int, queued: bool}>> $listeners
     * @throws InvalidListenerException
     */
    public static function fromArray(array $listeners): self
    {
        $registry = new self();

        foreach ($listeners as $eventClass => $entries) {
            if (!is_string($eventClass) || preg_match(self::CLASS_STRING_PATTERN, $eventClass) !== 1) {
                throw InvalidListenerException::forInvalidEventKey($eventClass);
            }

            if (!is_array($entries) || !array_is_list($entries)) {
                throw InvalidListenerException::forNonListEntries($eventClass);
            }

            /** @var array<string, true> $seenInEvent */
            $seenInEvent = [];
            $validated = [];

            foreach ($entries as $entry) {
                if (!is_array($entry) || !self::hasExactListenerKeys($entry)) {
                    throw InvalidListenerException::forMalformedCacheEntry($eventClass);
                }

                $class = $entry['class'];
                $method = $entry['method'];
                $priority = $entry['priority'];
                $queued = $entry['queued'];

                if (
                    !is_string($class) || preg_match(self::CLASS_STRING_PATTERN, $class) !== 1
                    || !is_string($method) || preg_match(self::IDENTIFIER_PATTERN, $method) !== 1
                    || !is_int($priority)
                    || !is_bool($queued)
                ) {
                    throw InvalidListenerException::forMalformedCacheEntry($eventClass);
                }

                $key = $class . '::' . $method;

                if (isset($seenInEvent[$key])) {
                    throw InvalidListenerException::forDuplicateCacheEntry($eventClass, $class, $method);
                }

                $seenInEvent[$key] = true;
                $validated[] = ['class' => $class, 'method' => $method, 'priority' => $priority, 'queued' => $queued];
                $registry->registeredClasses[$class] = true;
            }

            usort(
                $validated,
                static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']
                    ?: $a['class'] <=> $b['class']
                    ?: $a['method'] <=> $b['method'],
            );

            $registry->listeners[$eventClass] = $validated;
        }

        return $registry;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function hasExactListenerKeys(array $entry): bool
    {
        $expected = ['class', 'method', 'priority', 'queued'];
        $actual = array_keys($entry);

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
