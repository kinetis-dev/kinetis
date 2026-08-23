<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Kinetis\Async\Exception\ConnectionException;
use Fiber;
use Revolt\EventLoop;

/**
 * A non-blocking TCP client. connect()/read()/write() suspend the calling
 * Fiber while the underlying stream isn't ready, registering a Revolt
 * watcher that resumes it — the worker is free to run other Fibers (or
 * process other watchers) for the duration instead of blocking on socket
 * I/O. This wraps low-level socket operations directly on top of Revolt,
 * rather than hand-rolling a reactor; concurrently() is what actually
 * runs multiple Sockets side by side.
 *
 * Calling these methods outside a Fiber is a programming error — there
 * would be nothing to resume — and surfaces as PHP's own FiberError from
 * the Fiber::suspend() call, not a silent hang.
 */
final class Socket
{
    private function __construct(
        private mixed $stream,
    ) {}

    /**
     * $timeoutSeconds bounds the connect itself only (not read()/write());
     * null (the default) waits indefinitely. A stream becoming writable is
     * not on its own proof the connection actually succeeded — a refused
     * or reset connection can make the stream both readable and writable
     * too — so this also checks stream_socket_get_name($stream, true) (the
     * remote peer name) afterward, which is only available on a genuinely
     * established connection.
     */
    public static function connect(string $host, int $port, ?float $timeoutSeconds = null): self
    {
        $stream = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            timeout: 0.0,
            flags: STREAM_CLIENT_ASYNC_CONNECT,
        );

        if ($stream === false) {
            throw ConnectionException::couldNotConnect($host, $port, $errstr ?? '');
        }

        stream_set_blocking($stream, false);

        if (!self::awaitWritable($stream, $timeoutSeconds)) {
            fclose($stream);

            // awaitWritable() only ever returns false when $timeoutSeconds
            // was given in the first place and its own watcher fired first.
            assert($timeoutSeconds !== null);

            throw ConnectionException::timedOut($host, $port, $timeoutSeconds);
        }

        if (@stream_socket_get_name($stream, true) === false) {
            fclose($stream);

            throw ConnectionException::couldNotConnect($host, $port, 'connection refused or reset');
        }

        return new self($stream);
    }

    /**
     * @internal exposed so tests can drive the suspend/resume logic
     * against an in-process stream pair instead of a real network
     * connection.
     */
    public static function fromStream(mixed $stream): self
    {
        stream_set_blocking($stream, false);

        return new self($stream);
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($this->stream, $data);

            if ($written === false) {
                throw ConnectionException::closedWhileWriting();
            }

            if ($written === 0) {
                self::awaitWritable($this->stream);
                continue;
            }

            $data = substr($data, $written);
        }
    }

    /**
     * @param positive-int $length
     */
    public function read(int $length = 8192): string
    {
        self::awaitReadable($this->stream);

        $data = fread($this->stream, $length);

        return $data === false ? '' : $data;
    }

    public function close(): void
    {
        fclose($this->stream);
    }

    private static function awaitReadable(mixed $stream): void
    {
        $fiber = Fiber::getCurrent();

        EventLoop::onReadable($stream, static function (string $watcherId) use ($fiber): void {
            EventLoop::cancel($watcherId);
            $fiber?->resume();
        });

        Fiber::suspend();
    }

    /**
     * Returns true once the stream becomes writable, or false if
     * $timeoutSeconds elapses first — null (the default) waits
     * indefinitely, the same behavior this had before a timeout was
     * possible at all.
     */
    private static function awaitWritable(mixed $stream, ?float $timeoutSeconds = null): bool
    {
        $fiber = Fiber::getCurrent();
        $timedOut = false;
        $timeoutWatcherId = null;

        $writableWatcherId = EventLoop::onWritable($stream, static function (string $watcherId) use ($fiber, &$timeoutWatcherId): void {
            EventLoop::cancel($watcherId);

            // PHPStan/Psalm false positive, verified in isolation: flow
            // analysis for a closure capturing $timeoutWatcherId by
            // reference only sees the value at the point this closure is
            // *defined* (null), not that the enclosing scope reassigns it
            // afterward — a genuine watcher ID does reach here whenever
            // $timeoutSeconds was given and the writable watcher fires
            // first.
            if ($timeoutWatcherId !== null) { // @phpstan-ignore notIdentical.alwaysFalse
                /** @psalm-suppress NoValue Verified in isolation — see above. */
                EventLoop::cancel($timeoutWatcherId);
            }

            $fiber?->resume();
        });

        if ($timeoutSeconds !== null) {
            $timeoutWatcherId = EventLoop::delay($timeoutSeconds, static function () use ($fiber, $writableWatcherId, &$timedOut): void {
                $timedOut = true;
                EventLoop::cancel($writableWatcherId);
                $fiber?->resume();
            });
        }

        Fiber::suspend();

        return !$timedOut;
    }
}
