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
directory or namespace convention required; an installed package's
`extra.kinetis` scan roots contribute listeners the same way (see
{doc}`cli`). A controller constructor-injects the concrete `EventDispatcher` class —
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

With no queue configured, `ListenerInvokerInterface` resolves to
`SynchronousListenerInvoker`, which calls the listener inline — a
`ShouldQueue` listener works identically to any other one. Installing
{doc}`queue` and setting `QUEUE_CONNECTION` is the whole of the change:
that package's bootstrap binds `QueuedListenerInvoker`, and marked
listeners are pushed onto the configured queue from then on. An
application that wants them inline anyway binds its own invoker in
`bootstrap.php`, which runs later and wins.

`EventDispatcher` decides whether a listener is `ShouldQueue` from the
registry's own discovered metadata, before ever constructing anything —
`ListenerInvokerInterface::invoke()` receives the listener by
class-string, never a resolved instance, so a `ShouldQueue` listener's
constructor never runs in the process that dispatched the event.
`QueuedListenerInvoker` enqueues from that class-string alone;
`SendOrderConfirmation` above is constructed on the worker that pops the
resulting job — once per processing attempt, so a retried job builds and
invokes it again.

Two consequences worth knowing before marking a listener `ShouldQueue`:
propagation is decided in the dispatching process (see "Stopping
propagation" above), so a queued listener cannot stop the listeners
behind it; and the event has to survive serialization to reach the
worker, under the wire-value contract {doc}`queue` documents.

## Events Kinetis itself dispatches

Everything above is about events *you* define, like `OrderPlaced`. A
handful of moments inside the framework and its packages are dispatched
the same way — install the package, write a `#[Listener]` for whichever
one you need, nothing else to configure. Chosen deliberately narrow: a
framework-internal moment gets an event only when there's genuinely no
other way to hook it — a controller or a job's own code that already
calls something directly (`Session::regenerate()`, `AttemptThrottle::recordFailure()`)
is already standing in the right place to react itself, so those don't
get one.

| Event | Package | Fired when | Payload |
|---|---|---|---|
| `Kinetis\Queue\Events\JobSucceeded` | `kinetis/queue` | A job's `handle()` returns without throwing and the backend has ack'd it. | `class`, `queue`, `attempts` |
| `Kinetis\Queue\Events\JobReleased` | `kinetis/queue` | A job's `handle()` throws but attempts hasn't reached the effective cap yet, so it goes back on the queue for another try. | `class`, `queue`, `attempts`, `exception` |
| `Kinetis\Queue\Events\JobFailedPermanently` | `kinetis/queue` | A job's `handle()` throws and attempts has reached the effective cap, so it's given up on instead of retried — the only record of it beyond the log entry that fires alongside it. | `class`, `queue`, `attempts`, `exception`, `args` (redacted per `#[Sensitive]`, the same array the log entry carries) |
| `Kinetis\Queue\Events\JobSettlementLost` | `kinetis/queue` | The backend rejects a job's `ack()`/`release()`/`fail()` as stale — the delivery this worker held was already settled elsewhere, or reclaimed after its reservation expired. Replaces whichever of the three events above the settlement would have earned, since no durable transition happened. | `class`, `queue`, `attempts`, `operation` (a `Kinetis\Queue\JobSettlement`), `stale`, `failure` (the job's own exception on the release/fail paths, `null` on the ack path) |
| `Kinetis\Migrations\Events\MigrationApplied` | `kinetis/migrations` | Once per migration `migrate` actually runs, in the order they ran. | `name` |
| `Kinetis\Migrations\Events\MigrationRolledBack` | `kinetis/migrations` | `migrate:rollback` undoes a migration — never fired when there was nothing to roll back. | `name` |
| `Kinetis\Console\Events\CommandFailed` | `kinetis/framework` | Any `vendor/bin/kinetis` command throws. Commands typically run outside any request context (cron, a Kubernetes CronJob, a deploy step), so this is the only place that can observe a failure without wrapping every command's own body in a try/catch. | `commandName`, `exception` |

All four queue events are dispatched from inside `QueueWorker::processNext()`'s
own request scope — the same one a job's `handle()` runs in — so a
listener can constructor-inject anything that scope can resolve, exactly
like the job itself can. The two migration events and `CommandFailed`
work the same way through the command's own request scope.

**The queue events are dispatched only after `QueueWorker` has already
committed to the outcome they describe** — `ack()`/`release()`/`fail()`
has already run against the backend by the time
`JobSucceeded`/`JobReleased`/`JobFailedPermanently` fires, and
`JobSettlementLost` fires only once the backend has refused the
settlement outright. At most one of the four fires for a given job —
none at all when a transition fails for any other reason, since that
exception stops the worker. A listener
throwing is a best-effort observer failure, logged and otherwise
ignored: it can never retroactively change what already happened to the
job, and it can never stop the worker from processing the next one. See
{doc}`queue`'s "Observers never decide or rewrite the outcome" for the
full contract, which applies to telemetry instrumentation the same way.

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
