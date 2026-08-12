<?php

declare(strict_types=1);

namespace Kinetis\Tests\Async;

use Kinetis\Async\Exception\ConnectionException;
use Kinetis\Async\Socket;
use Kinetis\Async\Timer;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Kinetis\Async\concurrently;

final class SocketTest extends TestCase
{
    public function test_reads_data_already_available_on_the_stream(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($b, 'hello');

        $results = concurrently([
            static fn (): string => Socket::fromStream($a)->read(),
        ]);

        self::assertSame('hello', $results[0]);
    }

    public function test_read_suspends_until_data_actually_arrives(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $results = concurrently([
            static fn (): string => Socket::fromStream($a)->read(),
            static function () use ($b): null {
                // If Socket::read() didn't actually suspend and instead
                // returned immediately (e.g. an empty string from a
                // non-blocking read with nothing available yet), the
                // assertion above would see '' instead of 'delayed'.
                Timer::delay(0.02);
                fwrite($b, 'delayed');

                return null;
            },
        ]);

        self::assertSame('delayed', $results[0]);
    }

    public function test_write_then_read_round_trips_through_the_pair(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $results = concurrently([
            static function () use ($a): string {
                $socket = Socket::fromStream($a);
                $socket->write('ping');

                return $socket->read(4);
            },
            static function () use ($b): null {
                $peer = Socket::fromStream($b);
                $peer->write($peer->read(4));

                return null;
            },
        ]);

        self::assertSame('ping', $results[0]);
    }

    public function test_connect_establishes_a_real_tcp_connection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);

        $address = stream_socket_get_name($server, false);
        [$host, $portString] = explode(':', $address);
        $port = (int) $portString;

        $fiber = new \Fiber(static function () use ($host, $port): string {
            $socket = Socket::connect($host, $port);
            $socket->write('ping');

            return $socket->read(4);
        });
        $fiber->start();

        $client = stream_socket_accept($server, 5.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);

        EventLoop::onReadable($client, static function (string $watcherId, $stream): void {
            EventLoop::cancel($watcherId);
            fwrite($stream, fread($stream, 4));
        });

        EventLoop::run();

        self::assertSame('ping', $fiber->getReturn());
    }

    public function test_connect_throws_a_clear_error_when_the_connection_is_refused(): void
    {
        // A real port with nothing listening — bound and immediately
        // closed, so the OS still has it available but nothing will ever
        // accept a connection to it.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $address = stream_socket_get_name($server, false);
        fclose($server);

        [$host, $portString] = explode(':', $address);
        $port = (int) $portString;

        $fiber = new \Fiber(static function () use ($host, $port): void {
            Socket::connect($host, $port);
        });
        $fiber->start();

        // A Fiber that throws while resuming from inside a Revolt watcher
        // callback (rather than synchronously during ->start()) surfaces
        // through EventLoop::run() as Revolt's own UncaughtThrowable
        // wrapper — Fiber itself has no getThrowable() method to ask
        // instead.
        try {
            EventLoop::run();
            self::fail('Expected an exception from the refused connection.');
        } catch (EventLoop\UncaughtThrowable $e) {
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function test_connect_times_out_against_an_unreachable_address(): void
    {
        $start = microtime(true);

        $fiber = new \Fiber(static function (): void {
            // A reserved, non-routable address: silently drops every
            // packet rather than refusing the connection, so nothing ever
            // becomes writable and the timeout runs out.
            Socket::connect('10.255.255.1', 81, timeoutSeconds: 0.3);
        });
        $fiber->start();

        try {
            EventLoop::run();
            self::fail('Expected a timeout exception.');
        } catch (EventLoop\UncaughtThrowable $e) {
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
            self::assertStringContainsString('timed out', $e->getPrevious()->getMessage());
        }

        self::assertLessThan(2.0, microtime(true) - $start);
    }

    /**
     * Proves the timeout watcher is genuinely cancelled once the
     * connection succeeds first, not left to fire later — a real
     * PHPStan false positive on this exact cancellation line (see
     * Socket::awaitWritable()'s own comment) was only trusted as a false
     * positive after confirming this behavior directly.
     */
    public function test_a_successful_connect_cancels_its_own_timeout_watcher(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $address = stream_socket_get_name($server, false);
        [$host, $portString] = explode(':', $address);
        $port = (int) $portString;

        $fiber = new \Fiber(static function () use ($host, $port): string {
            Socket::connect($host, $port, timeoutSeconds: 5.0);

            return 'connected';
        });
        $fiber->start();

        $client = @stream_socket_accept($server, 5.0);
        self::assertNotFalse($client);

        EventLoop::run();
        self::assertSame('connected', $fiber->getReturn());

        // If the timeout watcher were still pending, this second, otherwise
        // empty run() would block for the remainder of the 5s timeout
        // instead of returning immediately with nothing left to wait on.
        $start = microtime(true);
        EventLoop::run();
        self::assertLessThan(0.5, microtime(true) - $start);
    }
}
