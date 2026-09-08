<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\CancelledException;
use Amp\Redis\Protocol\QueryException;
use Amp\TimeoutCancellation;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Deadline;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\ConnectionFailed;
use Kinetis\Redis\Exception\OutcomeUnknown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

final class ClientTest extends TestCase
{
    #[Test]
    public function a_refused_connection_fails_before_anything_is_written(): void
    {
        $peer = new RespPeer();
        $endpoint = $peer->endpoint();
        $peer->close();

        $client = Client::create($endpoint, new ClientOptions(timeout: 1.0));

        try {
            $client->execute('GET', 'k');
            self::fail('Expected the connect to be refused.');
        } catch (ConnectionFailed $e) {
            self::assertStringContainsString($endpoint->authority(), $e->getMessage());
        }

        self::assertSame([], $peer->received());
    }

    #[Test]
    public function a_connection_dropped_after_the_command_arrived_is_unknown_and_never_resent(): void
    {
        $peer = new RespPeer([null]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 1.0));

        $this->expectException(OutcomeUnknown::class);

        try {
            $client->execute('INCR', 'counter');
        } finally {
            self::assertSame([['INCR', 'counter']], $peer->received());
            $client->close();
            $peer->close();
        }
    }

    #[Test]
    public function a_silent_reply_spends_the_budget_and_the_next_operation_reconnects(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE, RespPeer::bulk('second')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.2));

        try {
            $client->execute('GET', 'first');
            self::fail('Expected the operation budget to expire.');
        } catch (OutcomeUnknown) {
            // The command was written and never answered.
        }

        self::assertSame('second', $client->execute('GET', 'second'));
        self::assertSame([['GET', 'first'], ['GET', 'second']], $peer->received());
        self::assertSame(2, $peer->connections());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function an_expired_budget_settles_every_waiter_on_the_connection_once(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE, RespPeer::SILENCE, RespPeer::SILENCE]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.2));

        $futures = [
            async(static fn () => $client->execute('GET', 'a')),
            async(static fn () => $client->execute('GET', 'b')),
            async(static fn () => $client->execute('GET', 'c')),
        ];

        $failures = 0;

        foreach ($futures as $future) {
            try {
                $future->await();
            } catch (OutcomeUnknown) {
                $failures++;
            }
        }

        self::assertSame(3, $failures);
        self::assertSame([['GET', 'a'], ['GET', 'b'], ['GET', 'c']], $peer->received());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function concurrent_operations_pipeline_in_order_on_one_connection(): void
    {
        $peer = new RespPeer([RespPeer::bulk('one'), RespPeer::bulk('two'), RespPeer::bulk('three')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        $values = [
            async(static fn () => $client->execute('GET', 'a')),
            async(static fn () => $client->execute('GET', 'b')),
            async(static fn () => $client->execute('GET', 'c')),
        ];

        self::assertSame(['one', 'two', 'three'], array_map(static fn ($f) => $f->await(), $values));
        self::assertSame([['GET', 'a'], ['GET', 'b'], ['GET', 'c']], $peer->received());
        self::assertSame(1, $peer->connections());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function the_connection_is_referenced_only_while_a_reply_is_outstanding(): void
    {
        $peer = new RespPeer([RespPeer::bulk('value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        $pending = async(static fn () => $client->execute('GET', 'k'));

        // The peer holds nothing referenced, so the loop runs only for
        // as long as the link keeps its own socket referenced.
        EventLoop::run();
        self::assertTrue($pending->isComplete(), 'The loop returned before the outstanding reply arrived.');
        self::assertSame('value', $pending->await());

        // Idle now, with the connection still open: nothing is left to
        // keep the loop alive, which is what concurrently() needs.
        EventLoop::run();

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_spent_budget_leaves_nothing_registered_on_the_loop(): void
    {
        $peer = new RespPeer([RespPeer::SILENCE, RespPeer::bulk('value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.2));

        try {
            $client->execute('GET', 'first');
            self::fail('Expected the operation budget to expire.');
        } catch (OutcomeUnknown) {
            // The connection was discarded with the command unanswered.
        }

        // Neither the expired budget's timer nor the discarded
        // connection's read loop is left holding the loop open, and the
        // next operation still reaches the peer.
        EventLoop::run();
        self::assertSame('value', $client->execute('GET', 'second'));
        EventLoop::run();

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_write_the_peer_never_drains_is_bounded_by_the_budget(): void
    {
        // The peer accepts the connection and never reads it, so the
        // kernel's buffers fill and Amp's own write suspends. Nothing
        // in that wait is cancellable; the budget has to end it.
        $peer = new RespPeer([], drains: false);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 0.3));
        $payload = str_repeat('x', 4 * 1024 * 1024);

        $started = hrtime(true);
        $write = async(static fn () => $client->execute('SET', 'k', $payload));

        try {
            $write->await(new TimeoutCancellation(5.0));
            self::fail('Expected the undrained write to fail.');
        } catch (OutcomeUnknown) {
            // Bytes may have reached Redis, so the outcome is unknown.
        } catch (CancelledException) {
            $write->ignore();
            self::fail('The write outlived the operation budget.');
        }

        self::assertLessThan(2.0, (hrtime(true) - $started) / 1_000_000_000);

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_spent_budget_is_refused_before_anything_is_dispatched(): void
    {
        $peer = new RespPeer([RespPeer::bulk('warm'), RespPeer::bulk('later')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        self::assertSame('warm', $client->execute('GET', 'warm'));

        $spent = Deadline::in(0.05);
        delay(0.1);

        try {
            $client->pipeline([['GET', 'never']], $spent);
            self::fail('Expected the spent budget to be refused.');
        } catch (ConnectionFailed) {
            // Nothing was dispatched, so the caller may retry.
        }

        try {
            $client->runScript('return 1', ['k'], [], $spent, asking: false);
            self::fail('Expected the spent budget to be refused for a script dispatch too.');
        } catch (ConnectionFailed) {
            // The same rule on the second dispatch of one operation.
        }

        // Only the operation was refused: the connection every other
        // fiber shares is untouched and still serving.
        self::assertSame('later', $client->execute('GET', 'later'));
        self::assertSame([['GET', 'warm'], ['GET', 'later']], $peer->received());
        self::assertSame(1, $peer->connections());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function a_client_nobody_holds_is_collected_and_its_connection_closes(): void
    {
        $peer = new RespPeer([RespPeer::bulk('value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        self::assertSame('value', $client->execute('GET', 'k'));

        $link = \WeakReference::create($client->link());
        $client = null;

        self::assertNull($link->get(), 'The read loop kept alive the link it only weakly references.');

        // No close() was called: the collected link's destructor closed
        // the socket, and the peer saw the connection go.
        delay(0.1);
        self::assertSame(1, $peer->disconnects());

        $peer->close();
    }

    #[Test]
    public function a_script_sends_evalsha_first_and_eval_once_the_node_reports_noscript(): void
    {
        $peer = new RespPeer([
            RespPeer::error('NOSCRIPT No matching script.'),
            ":7\r\n",
        ]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        self::assertSame(7, $client->script('k', 'return 7', ['k']));
        self::assertSame(['EVALSHA', 'EVAL'], $peer->commands());

        $client->close();
        $peer->close();
    }

    #[Test]
    public function an_ordinary_redis_error_reaches_the_caller_unchanged(): void
    {
        $peer = new RespPeer([RespPeer::error('WRONGTYPE Operation against a key holding the wrong kind of value')]);
        $client = Client::create($peer->endpoint(), new ClientOptions(timeout: 2.0));

        $this->expectException(QueryException::class);

        try {
            $client->execute('INCR', 'k');
        } finally {
            $client->close();
            $peer->close();
        }
    }

    #[Test]
    public function a_single_node_allows_cross_slot_keys_and_reports_itself_as_the_only_node(): void
    {
        $client = Client::create(Endpoint::parse('127.0.0.1:6379'), new ClientOptions());

        self::assertTrue($client->allowsCrossSlotKeys());
        self::assertSame([$client], $client->nodes());
    }
}
