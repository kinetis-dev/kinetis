<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisException;
use InvalidArgumentException;
use Kinetis\Redis\Cluster\HashSlot;
use Kinetis\Redis\Cluster\Redirect;
use Kinetis\Redis\Cluster\RedirectKind;
use Kinetis\Redis\Cluster\SlotMap;
use Kinetis\Redis\Exception\RedirectLimitExceeded;
use Kinetis\Redis\Exception\TopologyUnavailable;

/**
 * A client for a Redis Cluster, routing each command to the master that
 * owns its caller-named routing key.
 *
 * The slot map is read from the configured seeds on the first operation
 * and never before, and each node's connection is opened on its own
 * first command. Under a boot-and-die runtime every request therefore
 * pays one CLUSTER SLOTS round trip; under a persistent worker the map
 * and the connections live as long as the client.
 *
 * MOVED patches the one slot it names and the command is sent straight
 * to the target the reply named: that reply is proof of both, so
 * re-reading the topology first would spend the operation's budget on a
 * seed for an answer already in hand. ASK leaves ownership alone and
 * sends ASKING and the command as one write on the target's existing
 * connection. A command is re-sent only after a reply that proves the
 * node did not execute it; a connection failure is never a reason to
 * re-send.
 *
 * Discovery reads CLUSTER SLOTS from the seeds in order, giving each an
 * equal share of what is left of the budget, and concurrent fibers
 * share one in-flight read.
 *
 * Redis Cluster has no SELECT, so a non-zero database is refused here
 * rather than failing later on every node.
 */
final class ClusterClient implements RoutedExecutor
{
    /**
     * Attempts in total for one operation, not retries on top of a
     * first try. A healthy resharding needs a MOVED and at most one ASK
     * for the same key; anything beyond this is slots flapping.
     */
    private const int MAX_ATTEMPTS = 6;

    /** @var array<string, Client> */
    private array $clients = [];

    private ?SlotMap $slots = null;

    /** @var ?Future<SlotMap> */
    private ?Future $refreshing = null;

    /** @param non-empty-list<Endpoint> $seeds */
    private function __construct(
        private readonly array $seeds,
        private readonly ClientOptions $options,
    ) {}

    /** @param non-empty-list<Endpoint> $seeds */
    public static function create(array $seeds, #[\SensitiveParameter] ClientOptions $options): self
    {
        if ($seeds === []) {
            throw new InvalidArgumentException('A Redis Cluster client needs at least one seed endpoint.');
        }

        if ($options->database !== 0) {
            throw new InvalidArgumentException('Redis Cluster serves database 0 only; a non-zero database cannot be selected.');
        }

        return new self($seeds, $options);
    }

    #[\Override]
    public function executeKeyed(
        #[\SensitiveParameter] string $routingKey,
        string $command,
        #[\SensitiveParameter] int|float|string ...$parameters,
    ): mixed {
        $arguments = array_values($parameters);

        return $this->follow(
            HashSlot::calculate($routingKey),
            static fn (
                #[\SensitiveParameter] Client $client,
                bool $asking,
                #[\SensitiveParameter] Deadline $deadline,
            ): mixed => $asking
                ? $client->pipeline([['ASKING', []], [$command, $arguments]], $deadline)[1]
                : $client->pipeline([[$command, $arguments]], $deadline)[0],
        );
    }

    #[\Override]
    public function script(
        #[\SensitiveParameter] string $routingKey,
        #[\SensitiveParameter] string $script,
        #[\SensitiveParameter] array $keys,
        #[\SensitiveParameter] array $arguments = [],
    ): mixed {
        return $this->follow(
            HashSlot::calculate($routingKey),
            static fn (
                #[\SensitiveParameter] Client $client,
                bool $asking,
                #[\SensitiveParameter] Deadline $deadline,
            ): mixed => $client->runScript($script, $keys, $arguments, $deadline, $asking),
        );
    }

    #[\Override]
    public function allowsCrossSlotKeys(): bool
    {
        return false;
    }

    #[\Override]
    public function nodes(): array
    {
        $deadline = $this->options->deadline();
        $masters = $this->refresh($deadline)->masters();

        return array_map($this->clientFor(...), $masters);
    }

    #[\Override]
    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];
        $this->slots = null;
    }

    /**
     * $run captured the caller's command and is handed the node client,
     * whose options hold the password, so it and its first parameter are
     * both marked sensitive.
     *
     * @param \Closure(Client, bool, Deadline): mixed $run
     * @throws RedisException
     */
    private function follow(int $slot, #[\SensitiveParameter] \Closure $run): mixed
    {
        $deadline = $this->options->deadline();
        $client = $this->clientFor($this->map($deadline)->endpointForSlot($slot));
        $asking = false;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $run($client, $asking, $deadline);
            } catch (QueryException $e) {
                $redirect = Redirect::tryParse($e->getMessage());

                if ($redirect === null) {
                    throw $e;
                }

                $client = $this->clientFor($redirect->target);
                $asking = $redirect->kind === RedirectKind::Ask;

                if ($redirect->kind === RedirectKind::Moved) {
                    // The reply names the slot and its new owner, so
                    // the map is patched from it and the command goes
                    // to that node. A stale or silent seed must not
                    // spend the budget before a target this reply has
                    // already proven is tried.
                    $this->slots?->assign($redirect->slot, $redirect->target);
                }
            }
        }

        throw new RedirectLimitExceeded(
            'A Redis Cluster operation was redirected ' . self::MAX_ATTEMPTS . ' times without reaching a node that owns the key.',
        );
    }

    private function map(#[\SensitiveParameter] Deadline $deadline): SlotMap
    {
        return $this->slots ?? $this->refresh($deadline);
    }

    /**
     * Reads CLUSTER SLOTS from the first seed that answers. Concurrent
     * callers share one in-flight read, so a resharding under load does
     * not fan one discovery per fiber at the first seed.
     *
     * A fiber joining another's read waits on its own budget and no
     * longer, and reports its own expiry as a topology failure: the
     * leader is left to finish for whoever is still waiting, and every
     * caller of this package sees a RedisException rather than a
     * cancellation from a wait it never made itself.
     */
    private function refresh(#[\SensitiveParameter] Deadline $deadline): SlotMap
    {
        $pending = $this->refreshing;

        if ($pending !== null) {
            try {
                return $pending->await($deadline->cancellation());
            } catch (CancelledException $e) {
                throw new TopologyUnavailable(
                    'The Redis operation budget expired while another fiber was reading the cluster topology.',
                    0,
                    $e,
                );
            }
        }

        /** @var DeferredFuture<SlotMap> $deferred */
        $deferred = new DeferredFuture();
        $this->refreshing = $deferred->getFuture();
        $this->refreshing->ignore();

        try {
            $map = $this->discover($deadline);
        } catch (\Throwable $e) {
            $this->refreshing = null;
            $deferred->error($e);

            throw $e;
        }

        $this->slots = $map;
        $this->refreshing = null;
        $deferred->complete($map);

        return $map;
    }

    private function discover(#[\SensitiveParameter] Deadline $deadline): SlotMap
    {
        $failure = null;
        $untried = count($this->seeds);

        foreach ($this->seeds as $seed) {
            // An equal share of what is left of the one operation
            // budget, so a seed that accepts CLUSTER SLOTS and never
            // answers cannot spend all of it and leave a healthy seed
            // behind it untried. A seed that fails early hands the
            // rest of its share to the seeds after it.
            $attempt = Deadline::in($deadline->remaining() / $untried--);

            try {
                $reply = $this->clientFor($seed)->pipeline([['CLUSTER', ['SLOTS']]], $attempt)[0];

                return SlotMap::parse($reply, $seed);
            } catch (RedisException $e) {
                $failure ??= $e;
            }
        }

        throw new TopologyUnavailable(
            'No Redis Cluster seed returned a usable slot map: ' . ($failure?->getMessage() ?? 'no seeds were tried.'),
            0,
            $failure,
        );
    }

    private function clientFor(Endpoint $endpoint): Client
    {
        return $this->clients[$endpoint->authority()] ??= Client::create($endpoint, $this->options);
    }
}
