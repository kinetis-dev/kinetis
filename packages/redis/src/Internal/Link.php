<?php

declare(strict_types=1);

namespace Kinetis\Redis\Internal;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Redis\Connection\RedisLink;
use Amp\Redis\Protocol\ProtocolException;
use Amp\Redis\Protocol\RedisResponse;
use Amp\Redis\RedisException;
use Kinetis\Redis\Deadline;
use Kinetis\Redis\Exception\ConnectionFailed;
use Kinetis\Redis\Exception\OutcomeUnknown;
use Revolt\EventLoop;

/**
 * A pipelined link to one node that never re-sends a command.
 *
 * Concurrent fibers share one socket. Each command is appended to the
 * pending queue and written in the order it was appended, and replies
 * are matched to it in that same order, so N fibers cost one round trip
 * rather than N.
 *
 * The contract, in full:
 *
 * - A failure while the connection is still being established is a
 *   {@see ConnectionFailed}, and so is a budget already spent when the
 *   command reaches this class. No byte of the command left this
 *   process, so the caller may retry it.
 * - Once the write begins, the command's outcome is no longer knowable
 *   from here. A connection loss, an expired operation budget, or a
 *   cancelled wait discards the connection and raises
 *   {@see OutcomeUnknown}. The command is never written a second time.
 *   The budget bounds the write itself as well as the wait for a reply:
 *   {@see Connection::write()} closes the socket rather than let
 *   backpressure suspend a caller past its deadline.
 * - Discarding a connection settles every frame on it exactly once, and
 *   the next operation opens a new connection.
 * - close() abandons any connection attempt in flight. A socket whose
 *   setup finishes after that is closed rather than published, so no
 *   command is ever written on a link a caller has already closed.
 * - The socket is referenced only while at least one reply is
 *   outstanding, so an idle link lets `EventLoop::run()` return.
 *
 * The one command a caller sends twice is a command Redis Cluster
 * answered with MOVED or ASK, and that reply is itself proof the node
 * did not execute it. {@see \Kinetis\Redis\ClusterClient} owns that
 * decision; this class never makes it.
 *
 * @internal
 */
final class Link implements RedisLink
{
    /** @var \SplQueue<Frame> */
    private readonly \SplQueue $pending;

    private ?Connection $connection = null;

    /** @var ?Future<Connection> */
    private ?Future $connecting = null;

    /**
     * The connection attempt in flight, if any. Its identity is what
     * makes an attempt "current": close() drops it and cancels it, and
     * an attempt that finds itself replaced never publishes its socket.
     */
    private ?DeferredCancellation $attempt = null;

    public function __construct(
        private readonly Connector $connector,
        private readonly float $timeout,
    ) {
        /** @var \SplQueue<Frame> */
        $this->pending = new \SplQueue();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param array<int|float|string> $parameters
     * @throws RedisException
     */
    #[\Override]
    public function execute(string $command, #[\SensitiveParameter] array $parameters): RedisResponse
    {
        return $this->run([[$command, array_values($parameters)]], Deadline::in($this->timeout))[0];
    }

    /**
     * Writes every command as one payload and returns their replies in
     * order. A loss part-way through the payload leaves all of them
     * unknown, which is why ASKING and its command travel this way.
     *
     * @param non-empty-list<array{string, list<int|float|string>}> $commands
     * @return non-empty-list<RedisResponse>
     * @throws RedisException
     */
    public function run(#[\SensitiveParameter] array $commands, #[\SensitiveParameter] Deadline $deadline): array
    {
        $this->requireUnspent($deadline);
        $connection = $this->establish($deadline);
        // Connecting can consume what was left. Writing on a spent
        // budget would close the socket the instant the first byte
        // was queued and report an unknown outcome for a command that
        // never reached Redis at all.
        $this->requireUnspent($deadline);

        $payload = '';
        $frames = [];

        foreach ($commands as [$command, $parameters]) {
            $payload .= Connection::encode($command, $parameters);
            $frame = new Frame();
            $frames[] = $frame;
            $this->pending->enqueue($frame);
        }

        $connection->reference();

        try {
            $connection->write($payload, $deadline->cancellation());
        } catch (OutcomeUnknown $e) {
            $this->discard($connection, $e);

            throw $e;
        }

        $responses = [];

        foreach ($frames as $frame) {
            $responses[] = $this->awaitReply($frame, $connection, $deadline);
        }

        /** @var non-empty-list<RedisResponse> */
        return $responses;
    }

    public function close(): void
    {
        $attempt = $this->attempt;
        $connection = $this->connection;
        $this->attempt = null;
        $this->connecting = null;
        $this->connection = null;

        // Aborts a connection still being established: its setup must
        // not go on to hand a socket to a caller that has closed.
        $attempt?->cancel();

        if ($connection !== null) {
            $connection->close();
        }

        while (!$this->pending->isEmpty()) {
            $this->pending->dequeue()->fail(new OutcomeUnknown('The Redis connection was closed.'));
        }
    }

    /**
     * Matches one reply to the oldest outstanding frame. Public for the
     * read loop below, which cannot reach a private method through the
     * weak reference it holds.
     */
    public function settle(Connection $connection, RedisResponse $response): void
    {
        if ($this->pending->isEmpty()) {
            $this->discard($connection, new ProtocolException('Redis sent a reply with no command outstanding.'));

            return;
        }

        $frame = $this->pending->dequeue();

        if ($this->pending->isEmpty()) {
            $connection->unreference();
        }

        $frame->complete($response);
    }

    /**
     * Drops $connection and settles everything still on it. A second
     * call for a connection already replaced is a no-op, so a reply
     * timeout and the read loop noticing the close cannot settle the
     * same frame twice.
     */
    public function discard(Connection $connection, RedisException $reason): void
    {
        $connection->close();

        if ($this->connection !== $connection) {
            return;
        }

        $this->connection = null;

        while (!$this->pending->isEmpty()) {
            $this->pending->dequeue()->fail($reason);
        }
    }

    private function awaitReply(
        Frame $frame,
        Connection $connection,
        #[\SensitiveParameter] Deadline $deadline,
    ): RedisResponse {
        try {
            return $frame->future()->await($deadline->cancellation());
        } catch (CancelledException $e) {
            $expired = new OutcomeUnknown(
                'The Redis operation budget expired with the command already written and no reply received.',
                0,
                $e,
            );

            $this->discard($connection, $expired);

            throw $expired;
        }
    }

    /** @throws ConnectionFailed */
    private function requireUnspent(#[\SensitiveParameter] Deadline $deadline): void
    {
        if ($deadline->expired()) {
            throw new ConnectionFailed('The Redis operation budget was spent before the command was dispatched.');
        }
    }

    /** @throws ConnectionFailed */
    private function establish(#[\SensitiveParameter] Deadline $deadline): Connection
    {
        $connection = $this->connection;

        if ($connection !== null && !$connection->isClosed()) {
            return $connection;
        }

        $connecting = $this->connecting;

        if ($connecting !== null) {
            try {
                return $connecting->await($deadline->cancellation());
            } catch (CancelledException $e) {
                throw new ConnectionFailed(
                    'The Redis operation budget expired while the connection was still being established.',
                    0,
                    $e,
                );
            }
        }

        /** @var DeferredFuture<Connection> $deferred */
        $deferred = new DeferredFuture();
        $this->connecting = $deferred->getFuture();
        $this->connecting->ignore();
        $this->attempt = $attempt = new DeferredCancellation();

        try {
            // The budget bounds the attempt; the attempt's own
            // cancellation is what close() pulls to abort it.
            $connection = $this->connector->connect(
                new CompositeCancellation($deadline->cancellation(), $attempt->getCancellation()),
            );

            if ($this->attempt !== $attempt) {
                $connection->close();

                throw new ConnectionFailed('The Redis link was closed while its connection was being established.');
            }
        } catch (\Throwable $e) {
            $failure = $e instanceof ConnectionFailed
                ? $e
                : new ConnectionFailed('Failed to connect to Redis: ' . $e->getMessage(), 0, $e);

            // Left alone once this attempt is no longer the current
            // one: what replaced it owns those fields now.
            if ($this->attempt === $attempt) {
                $this->attempt = null;
                $this->connecting = null;
            }

            $deferred->error($failure);

            throw $failure;
        }

        $this->attempt = null;
        $this->connection = $connection;
        $this->connecting = null;
        $this->read($connection);
        $deferred->complete($connection);

        return $connection;
    }

    /**
     * The read loop holds a weak reference so a link nobody uses any
     * more is collected, its destructor closes the socket, and this
     * fiber ends. A strong reference would keep both alive for the
     * lifetime of the worker.
     */
    private function read(Connection $connection): void
    {
        $link = \WeakReference::create($this);

        EventLoop::queue(static function () use ($connection, $link): void {
            try {
                while (null !== $response = $connection->receive()) {
                    $target = $link->get();

                    if ($target === null) {
                        break;
                    }

                    $target->settle($connection, $response);
                    // Released before the next wait. A strong local
                    // held across receive() would keep the link, and
                    // its socket, alive for as long as the peer stayed
                    // quiet — the lifetime this weak reference exists
                    // to avoid.
                    $target = null;
                }

                $link->get()?->discard($connection, new OutcomeUnknown('The Redis connection closed before a reply arrived.'));
            } catch (\Throwable $e) {
                $link->get()?->discard($connection, new OutcomeUnknown('The Redis connection failed: ' . $e->getMessage(), 0, $e));
            }

            $connection->close();
        });
    }
}
