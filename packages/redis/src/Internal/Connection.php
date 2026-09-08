<?php

declare(strict_types=1);

namespace Kinetis\Redis\Internal;

use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Redis\Protocol\RedisResponse;
use Amp\Redis\Protocol\RespParser;
use Amp\Socket\Socket;
use Kinetis\Redis\Exception\OutcomeUnknown;
use Revolt\EventLoop;

/**
 * One socket, with the vendor RESP parser feeding a FIFO queue of
 * replies.
 *
 * write() takes an already-encoded payload rather than one command's
 * arguments, which is what lets a caller put two commands on the wire
 * as a single write. Amp's writable stream performs the immediate
 * fwrite and queues any remainder before it suspends, so the bytes of
 * one write() are contiguous and no other fiber's command can land
 * between them. ASKING plus its redirected command depends on that.
 *
 * A payload carries a credential — AUTH's argument — or a caller's own
 * keys and values. Every parameter holding it is marked sensitive, here
 * and in each frame above, so PHP records a redaction marker among the
 * trace arguments of anything this package raises. A write that fails
 * raises inside amphp/byte-stream, whose frames hold the payload as
 * their own unmarked argument, so that exception is reported as a fixed
 * message and never chained onto the {@see OutcomeUnknown}. The
 * cancellation write() takes is marked for a second route to those same
 * frames, which {@see \Kinetis\Redis\Deadline} states.
 *
 * @internal
 */
final class Connection
{
    /** @var ConcurrentIterator<RedisResponse> */
    private readonly ConcurrentIterator $replies;

    public function __construct(private readonly Socket $socket)
    {
        $queue = new Queue();
        $this->replies = $queue->iterate();

        EventLoop::queue(static function () use ($socket, $queue): void {
            /** @psalm-suppress InvalidArgument */
            $parser = new RespParser($queue->push(...));

            try {
                while (null !== $chunk = $socket->read()) {
                    $parser->push($chunk);
                }

                $parser->cancel();
                $queue->complete();
            } catch (\Throwable $e) {
                $queue->error($e);
            }

            $socket->close();
        });
    }

    /** @param list<int|float|string> $parameters */
    public static function encode(string $command, #[\SensitiveParameter] array $parameters): string
    {
        $arguments = [$command, ...array_map(strval(...), $parameters)];
        $payload = '*' . count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            $payload .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        return $payload;
    }

    /**
     * Amp's writable stream has no cancellation of its own: under
     * backpressure it suspends until the peer drains, for as long as
     * that takes. Closing the socket is the only thing that ends that
     * wait, so the subscription below does exactly that and nothing
     * else, and it is dropped again — once — whether the write
     * succeeds, is cancelled, or fails. The flag keeps a cancellation
     * that fires as the write completes from closing a socket the
     * write already left healthy: unsubscribing cannot recall a
     * callback the loop has queued.
     *
     * @throws OutcomeUnknown
     */
    public function write(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] Cancellation $cancellation): void
    {
        $writing = true;
        $id = $cancellation->subscribe(function () use (&$writing): void {
            // $writing is captured by reference, so a callback the loop
            // already queued after a completed write reads the false the
            // finally block below leaves behind.
            if ($writing) { // @phpstan-ignore if.alwaysTrue
                $this->socket->close();
            }
        });

        try {
            $this->socket->write($payload);
        } catch (StreamException) {
            throw new OutcomeUnknown($cancellation->isRequested()
                ? 'The Redis operation budget expired while the command was being written.'
                : 'The Redis connection failed while the command was being written.');
        } finally {
            $writing = false;
            $cancellation->unsubscribe($id);
        }
    }

    /** Null once the peer closed cleanly with nothing left to read. */
    public function receive(#[\SensitiveParameter] ?Cancellation $cancellation = null): ?RedisResponse
    {
        if (!$this->replies->continue($cancellation)) {
            return null;
        }

        /** @var RedisResponse */
        return $this->replies->getValue();
    }

    /** Keeps the event loop alive while a reply is outstanding. */
    public function reference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->reference();
        }
    }

    public function unreference(): void
    {
        if ($this->socket instanceof ResourceStream) {
            $this->socket->unreference();
        }
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }
}
