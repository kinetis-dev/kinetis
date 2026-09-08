<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\CancelledException;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\ConnectionFailed;
use Kinetis\Redis\Exception\OutcomeUnknown;
use Kinetis\Redis\Exception\RedirectLimitExceeded;
use Kinetis\Redis\Exception\TopologyUnavailable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class ClusterClientTest extends TestCase
{
    /** @var list<RespPeer> */
    private array $peers = [];

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            $peer->close();
        }

        $this->peers = [];
    }

    #[Test]
    public function a_command_reaches_the_master_the_slot_map_names(): void
    {
        // "k" hashes to slot 7629, so the second range owns it.
        $owner = $this->peer([RespPeer::bulk('value')]);
        $seed = $this->peer([$this->slots([[0, 5000, $owner], [5001, 16383, $owner]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        self::assertSame('value', $client->executeKeyed('k', 'GET', 'k'));
        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());
        self::assertSame([['GET', 'k']], $owner->received());

        $client->close();
    }

    #[Test]
    public function a_moved_reply_routes_to_the_named_target_and_patches_the_slot(): void
    {
        $target = $this->peer([RespPeer::bulk('first'), RespPeer::bulk('second')]);
        $stale = $this->peer([RespPeer::error('MOVED 7629 ' . $target->endpoint()->authority())]);
        $seed = $this->peer([$this->slots([[0, 16383, $stale]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        self::assertSame('first', $client->executeKeyed('k', 'GET', 'k'));
        // The patch outlives the operation, so the second call goes
        // straight to the target.
        self::assertSame('second', $client->executeKeyed('k', 'GET', 'k'));

        self::assertSame([['GET', 'k']], $stale->received());
        self::assertSame([['GET', 'k'], ['GET', 'k']], $target->received());
        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());

        $client->close();
    }

    #[Test]
    public function a_moved_target_is_tried_before_a_seed_that_never_answers(): void
    {
        $target = $this->peer([RespPeer::bulk('value')]);
        $stale = $this->peer([RespPeer::error('MOVED 7629 ' . $target->endpoint()->authority())]);
        // One usable slot map, then silence for anything asked after it.
        $seed = $this->peer([$this->slots([[0, 16383, $stale]]), RespPeer::SILENCE]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 1.0));

        self::assertSame('value', $client->executeKeyed('k', 'GET', 'k'));
        // The MOVED reply proves both the slot and its owner, so no
        // second topology read stands between it and the target.
        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());

        $client->close();
    }

    #[Test]
    public function a_seed_that_never_answers_leaves_the_next_seed_a_chance(): void
    {
        $owner = $this->peer([RespPeer::bulk('value')]);
        $silent = $this->peer([RespPeer::SILENCE]);
        $healthy = $this->peer([$this->slots([[0, 16383, $owner]])]);

        $client = ClusterClient::create(
            [$silent->endpoint(), $healthy->endpoint()],
            new ClientOptions(timeout: 1.0),
        );

        self::assertSame('value', $client->executeKeyed('k', 'GET', 'k'));
        self::assertSame([['CLUSTER', 'SLOTS']], $silent->received());
        self::assertSame([['CLUSTER', 'SLOTS']], $healthy->received(), 'The silent seed spent the whole budget.');

        $client->close();
    }

    #[Test]
    public function concurrent_operations_share_one_topology_read(): void
    {
        $owner = $this->peer([RespPeer::bulk('first'), RespPeer::bulk('second')]);
        // Delayed, so the second fiber is certain to arrive while the
        // first one's read is still in flight.
        $seed = $this->peer([RespPeer::after(0.1, $this->slots([[0, 16383, $owner]]))]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        $values = [
            async(static fn () => $client->executeKeyed('k', 'GET', 'k')),
            async(static fn () => $client->executeKeyed('k', 'GET', 'k')),
        ];

        self::assertSame(['first', 'second'], array_map(static fn ($f) => $f->await(), $values));
        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());

        $client->close();
    }

    #[Test]
    public function a_fiber_waiting_on_another_fibers_topology_read_fails_as_a_redis_exception(): void
    {
        // The seed drops the connection on CLUSTER SLOTS, so the read
        // fails with a second fiber already waiting on it.
        $seed = $this->peer([null]);
        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        $operations = [
            async(static fn () => $client->executeKeyed('k', 'GET', 'k')),
            async(static fn () => $client->executeKeyed('k', 'GET', 'k')),
        ];

        foreach ($operations as $operation) {
            try {
                $operation->await();
                self::fail('Expected the topology read to fail.');
            } catch (CancelledException) {
                self::fail('A shared topology read must not surface a cancellation to a caller.');
            } catch (TopologyUnavailable) {
                // Both the fiber that read and the fiber that waited.
            }
        }

        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());

        $client->close();
    }

    #[Test]
    public function an_ask_reply_sends_asking_and_the_command_on_the_targets_own_connection(): void
    {
        $target = $this->peer([RespPeer::bulk('OK'), RespPeer::bulk('migrating')]);
        $owner = $this->peer([RespPeer::error('ASK 7629 ' . $target->endpoint()->authority())]);
        $seed = $this->peer([$this->slots([[0, 16383, $owner]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        self::assertSame('migrating', $client->executeKeyed('k', 'GET', 'k'));
        self::assertSame([['ASKING'], ['GET', 'k']], $target->received());
        self::assertSame(1, $target->connections(), 'ASK reused the existing connection to the target.');

        $client->close();
    }

    #[Test]
    public function an_ask_reply_for_a_script_sends_eval_rather_than_evalsha(): void
    {
        $target = $this->peer([RespPeer::bulk('OK'), ":3\r\n"]);
        $owner = $this->peer([RespPeer::error('ASK 7629 ' . $target->endpoint()->authority())]);
        $seed = $this->peer([$this->slots([[0, 16383, $owner]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        self::assertSame(3, $client->script('k', 'return 3', ['k']));
        self::assertSame(['ASKING', 'EVAL'], $target->commands());
        self::assertSame(['EVALSHA'], $owner->commands());

        $client->close();
    }

    #[Test]
    public function a_slot_that_keeps_moving_fails_at_the_attempt_bound(): void
    {
        $flapping = $this->peer([]);
        $flapping->replies(array_fill(0, 8, RespPeer::error('MOVED 7629 ' . $flapping->endpoint()->authority())));
        $seed = $this->peer([$this->slots([[0, 16383, $flapping]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        $this->expectException(RedirectLimitExceeded::class);

        try {
            $client->executeKeyed('k', 'GET', 'k');
        } finally {
            self::assertCount(6, $flapping->received());
            self::assertSame([['CLUSTER', 'SLOTS']], $seed->received());
            $client->close();
        }
    }

    #[Test]
    public function an_owner_that_cannot_be_reached_drops_the_slot_map(): void
    {
        $healthy = $this->peer([RespPeer::bulk('value')]);
        // Bound to a loopback port and then closed, so a connection to
        // it is refused before a byte of the command can be written.
        $gone = $this->peer([]);
        $gone->close();
        $seed = $this->peer([
            $this->slots([[0, 16383, $gone]]),
            $this->slots([[0, 16383, $healthy]]),
        ]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        try {
            $client->executeKeyed('k', 'GET', 'k');
            self::fail('Expected the unreachable owner to fail the operation.');
        } catch (ConnectionFailed) {
            // Reported as it is: sending the command again is the
            // caller's decision, not this client's.
        }

        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received(), 'The failing operation read the topology a second time.');

        self::assertSame('value', $client->executeKeyed('k', 'GET', 'k'));
        self::assertSame(
            [['CLUSTER', 'SLOTS'], ['CLUSTER', 'SLOTS']],
            $seed->received(),
            'The next operation routed on the map its predecessor failed on instead of reading the topology again.',
        );
        self::assertSame([['GET', 'k']], $healthy->received(), 'The command that failed was sent again to the new owner.');

        $client->close();
    }

    #[Test]
    public function an_unknown_outcome_leaves_the_slot_map_in_place(): void
    {
        // The command is recorded and never answered, so the budget
        // expires with it already written.
        $owner = $this->peer([RespPeer::SILENCE, RespPeer::bulk('value')]);
        // One slot map and nothing after it: a second topology read
        // ends the connection and fails the test as TopologyUnavailable.
        $seed = $this->peer([$this->slots([[0, 16383, $owner]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 0.5));

        try {
            $client->executeKeyed('k', 'GET', 'k');
            self::fail('Expected the silent owner to spend the budget.');
        } catch (OutcomeUnknown) {
            // An ambiguous outcome, which says nothing about whether
            // the cached map is still correct.
        }

        self::assertSame('value', $client->executeKeyed('k', 'GET', 'k'));
        self::assertSame([['CLUSTER', 'SLOTS']], $seed->received(), 'An unknown outcome dropped the slot map.');
        self::assertSame([['GET', 'k'], ['GET', 'k']], $owner->received());

        $client->close();
    }

    #[Test]
    public function nodes_returns_one_client_per_current_master(): void
    {
        $first = $this->peer([]);
        $second = $this->peer([]);
        $seed = $this->peer([$this->slots([[0, 8191, $first], [8192, 16383, $second]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));
        $nodes = $client->nodes();

        self::assertCount(2, $nodes);
        self::assertSame(
            [$first->endpoint()->authority(), $second->endpoint()->authority()],
            array_map(static fn ($node): string => $node->endpoint->authority(), $nodes),
        );

        $client->close();
    }

    #[Test]
    public function a_cluster_never_allows_cross_slot_keys(): void
    {
        $client = ClusterClient::create([Endpoint::parse('127.0.0.1:6379')], new ClientOptions());

        self::assertFalse($client->allowsCrossSlotKeys());
    }

    #[Test]
    public function a_non_zero_database_is_refused_because_a_cluster_has_no_select(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClusterClient::create([Endpoint::parse('127.0.0.1:6379')], new ClientOptions(database: 3));
    }

    #[Test]
    public function a_seed_that_reports_an_incomplete_slot_map_is_refused(): void
    {
        $master = $this->peer([]);
        $seed = $this->peer([$this->slots([[0, 8191, $master]])]);

        $client = ClusterClient::create([$seed->endpoint()], new ClientOptions(timeout: 2.0));

        $this->expectException(TopologyUnavailable::class);

        try {
            $client->executeKeyed('k', 'GET', 'k');
        } finally {
            $client->close();
        }
    }

    /** @param list<?string> $script */
    private function peer(array $script): RespPeer
    {
        return $this->peers[] = new RespPeer($script);
    }

    /**
     * A CLUSTER SLOTS reply for the given [start, end, master] ranges.
     *
     * @param list<array{int, int, RespPeer}> $ranges
     */
    private function slots(array $ranges): string
    {
        $reply = '*' . count($ranges) . "\r\n";

        foreach ($ranges as [$start, $end, $peer]) {
            $endpoint = $peer->endpoint();
            $reply .= "*3\r\n:{$start}\r\n:{$end}\r\n*3\r\n"
                . RespPeer::bulk($endpoint->host)
                . ":{$endpoint->port}\r\n"
                . RespPeer::bulk(str_repeat('a', 40));
        }

        return $reply;
    }
}
