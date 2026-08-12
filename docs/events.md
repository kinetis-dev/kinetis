# Events

A plain [PSR-14](https://www.php-fig.org/psr/psr-14/) event dispatcher —
`Psr\EventDispatcher\EventDispatcherInterface` — with attribute-driven
listener registration.

## Writing an event and a listener

An event is a plain object, no base class or marker interface required:

```{code-block} php
final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
        public string $customerEmail,
    ) {}
}
```

A listener is any public method carrying `#[Listener]` — the attribute
takes no arguments, since the event class is inferred from the method's
own single parameter type:

```{code-block} php
use Kinetis\Events\Listener;

final readonly class SendOrderConfirmation
{
    public function __construct(private Mailer $mailer) {}

    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->mailer->send($event->customerEmail, 'Order confirmed');
    }
}
```

One class can carry multiple `#[Listener]` methods for different events,
the same way a controller can carry multiple routed methods.

## Dispatching

Nothing to register — any class anywhere under one of your own PSR-4
roots carrying a `#[Listener]` method is found automatically, with no
directory or namespace convention required. A controller constructor-injects the concrete `EventDispatcher` class —
not `Psr\EventDispatcher\EventDispatcherInterface` — and calls it
directly:

```{code-block} php
use Kinetis\Events\EventDispatcher;

final readonly class OrderController
{
    public function __construct(private EventDispatcher $events) {}

    #[Post('/orders')]
    public function store(#[Body] CreateOrderRequest $data): array
    {
        // ...
        $this->events->dispatch(new OrderPlaced($order->id, $data->email));

        return ['status' => 'created'];
    }
}
```

```{note}
Inject the concrete `EventDispatcher` class, not the PSR interface.
`EventDispatcher` is never explicitly registered anywhere — it's autowired
fresh per request, the same way `TransactionGuard` is — and PHP can only
autowire a concrete class by reflecting its constructor, not an unbound
interface.
```

Every listener registered for an event's exact class runs — a listener
for `OrderPlaced` does not also fire for a subclass.

## Ordering multiple listeners for the same event

`#[Listener(priority: int = 50)]` — bounded `0`-`100`, throwing
`InvalidArgumentException` outside that range — decides which listener
runs first when more than one is registered for the same event. Higher
runs earlier:

```{code-block} php
#[Listener(priority: 90)]
public function onOrderPlaced(OrderPlaced $event): void { /* runs first */ }

#[Listener(priority: 10)]
public function onOrderPlacedLast(OrderPlaced $event): void { /* runs last */ }
```

See [Stopping propagation](#stopping-propagation) below: once one
listener can prevent later ones from running at all, which listener runs
first is a real behavioral difference. Two listeners sharing a priority
are ordered alphabetically by class name, then by method name, so the
result never depends on filesystem/scan order. Restrict the discovery
scan for a large application with `LISTENER_DISCOVERY_PATHS` — see
{doc}`cli`.

## Stopping propagation

An event implementing PSR-14's `StoppableEventInterface` can stop later
listeners from running:

```{code-block} php
use Psr\EventDispatcher\StoppableEventInterface;

final class OrderPlaced implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
```

`EventDispatcher` checks `isPropagationStopped()` after every listener and
stops calling further ones the moment it returns `true`.

## Deferring a listener to a queue

A listener implementing `Kinetis\Events\ShouldQueue` is invoked through
`Kinetis\Events\ListenerInvokerInterface` instead of being called
directly:

```{code-block} php
use Kinetis\Events\Listener;
use Kinetis\Events\ShouldQueue;

final readonly class SendOrderConfirmation implements ShouldQueue
{
    public function __construct(private Mailer $mailer) {}

    #[Listener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->mailer->send($event->customerEmail, 'Order confirmed');
    }
}
```

By default, `ListenerInvokerInterface` resolves to
`SynchronousListenerInvoker`, which just calls the listener inline — a
`ShouldQueue` listener works identically to any other one until something
else is registered. {doc}`queue`'s `QueuedListenerInvoker` pushes it onto
a real queue instead:

```{code-block} php
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\QueuedListenerInvoker;

$app->instance(ListenerInvokerInterface::class, new QueuedListenerInvoker($queue));
```

## See also

- {doc}`queue` — `ShouldQueue`/`QueuedListenerInvoker`, for running a
  listener on a worker instead of inline.
- {doc}`container` — `RequestScope`, and why `EventDispatcher` is
  constructor-injected as a concrete class rather than resolved through
  the PSR-14 interface.
- {doc}`routing-validation` — attribute-driven request binding, following
  the same "no config files, just attributes on your own methods" style.
- {doc}`cli` — restricting namespace-based discovery for a large
  application, the mechanism `LISTENER_DISCOVERY_PATHS` follows.
- {doc}`caching` — how discovered listeners are stored in the production
  cache.
