<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\NotFoundException;
use Closure;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The ephemeral, per-request container. Created fresh for every incoming
 * request and discarded via dispose() when the request completes.
 *
 * Resolution order for an id with no local binding:
 *   1. Delegate to AppScope, but only if AppScope has an *explicit*
 *      registration for it — never an implicit one.
 *   2. Otherwise, autowire it locally. The instance is cached only for the
 *      remainder of this request (in this container's own binding table,
 *      which is wiped on dispose()) — it is never promoted to AppScope.
 *
 * This is what keeps an accidental `$container->get(SomeUnregisteredClass::class)`
 * from turning into a persistent, cross-request singleton.
 */
final class RequestScope implements ContainerInterface
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var list<callable(): void> */
    private array $disposeCallbacks = [];

    private bool $disposed = false;

    public function __construct(
        private readonly AppScope $parent,
    ) {}

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = true): void
    {
        $this->assertNotDisposed();
        $this->bindings[$id] = new Binding($this->normalizeConcrete($id, $concrete), $shared);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertNotDisposed();
        $binding = new Binding(static fn (): object => $instance);
        $binding->remember($instance);
        $this->bindings[$id] = $binding;
    }

    #[\Override]
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || $this->parent->has($id) || class_exists($id);
    }

    #[\Override]
    public function get(string $id): mixed
    {
        $this->assertNotDisposed();

        return $this->resolve($id);
    }

    /**
     * Registers a callback to run when this scope is disposed — the
     * generic hook mechanism the request lifecycle/reset protocol (DB
     * transaction rollback, releasing pooled connections, Fiber GC, ...)
     * is meant to attach to, without RequestScope needing to know about
     * any of those specifically.
     *
     * @param callable(): void $callback
     */
    public function onDispose(callable $callback): void
    {
        $this->assertNotDisposed();
        $this->disposeCallbacks[] = $callback;
    }

    /**
     * Runs every registered dispose callback, then discards all
     * request-scoped bindings and instances regardless of whether any
     * callback failed — a hook throwing must never leave this scope
     * un-disposed, or its bindings would leak into whatever reuses this
     * object next. Every callback still gets to run even if an earlier
     * one throws; the first failure (if any) is rethrown only after all
     * of them, and the wipe, have completed.
     *
     * Must be called once the request finishes; the container must not be
     * reused or held onto past that point.
     */
    public function dispose(): void
    {
        $firstError = null;

        foreach ($this->disposeCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        $this->bindings = [];
        $this->resolving = [];
        $this->disposeCallbacks = [];
        $this->disposed = true;

        if ($firstError !== null) {
            throw $firstError;
        }
    }

    /**
     * The application scope this request scope belongs to — for the
     * consumer that mints further per-unit-of-work scopes itself (a
     * queue worker creating a fresh scope per job, via
     * AppScope::createRequestScope()). Not a bypass for ordinary
     * resolution: get() with its explicit-delegation rule remains the
     * way to resolve services here.
     */
    public function appScope(): AppScope
    {
        return $this->parent;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    private function resolve(string $id): mixed
    {
        $binding = $this->bindings[$id] ?? null;

        if ($binding?->resolved() !== null) {
            return $binding->resolved();
        }

        if (isset($this->resolving[$id])) {
            throw CircularDependencyException::forPath([...array_keys($this->resolving), $id]);
        }

        if ($binding === null && $this->parent->has($id)) {
            return $this->parent->get($id);
        }

        if ($binding === null && !class_exists($id)) {
            throw NotFoundException::forId($id);
        }

        $this->resolving[$id] = true;

        try {
            $instance = $binding !== null
                ? ($binding->factory)($this)
                : Autowire::instantiate($id, $this);
        } finally {
            unset($this->resolving[$id]);
        }

        // See the identical note in AppScope::resolve() — $binding is
        // genuinely nullable here; the nullsafe is required, not redundant.
        // @phpstan-ignore-next-line nullsafe.neverNull
        $shared = $binding?->shared ?? true;

        if ($shared) {
            $binding ??= new Binding(static fn (): object => $instance);
            $binding->remember($instance);
            $this->bindings[$id] = $binding;
        }

        return $instance;
    }

    private function normalizeConcrete(string $id, Closure|string|null $concrete): Closure
    {
        if ($concrete === null) {
            $class = $this->assertClassString($id);

            return static fn (ContainerInterface $c): object => Autowire::instantiate($class, $c);
        }

        if (is_string($concrete)) {
            $class = $this->assertClassString($concrete);

            return static fn (ContainerInterface $c): object => Autowire::instantiate($class, $c);
        }

        return $concrete;
    }

    /**
     * @return class-string
     */
    private function assertClassString(string $id): string
    {
        if (!class_exists($id)) {
            throw new ContainerException(
                "Cannot bind \"{$id}\": no concrete implementation was given and \"{$id}\" is not an existing class."
            );
        }

        return $id;
    }

    private function assertNotDisposed(): void
    {
        if ($this->disposed) {
            throw new ContainerException('This request scope has already been disposed and cannot be reused.');
        }
    }
}
