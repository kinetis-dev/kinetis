<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests\Integration;

use Amp\Redis\Protocol\QueryException;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Cluster\HashSlot;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\Endpoint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Routing against a real Redis Cluster.
 *
 * A scripted peer proves the client sends what the protocol says; only a
 * real cluster proves the protocol was read correctly. Each case here
 * needs slots that move between masters while a client still holds a
 * stale map.
 *
 * Environment-gated on REDIS_CLUSTER_SEEDS. Every case that changes slot
 * ownership restores it in tearDown(), so the suite can run twice
 * against the same cluster.
 */
final class ClusterIntegrationTest extends TestCase
{
    /** Reserved slots, spaced far apart inside one master's original range. */
    private const int SLOT_OFFSET_MOVED = 100;
    private const int SLOT_OFFSET_SEQUENCE = 700;

    /** @var array<int, array{original: array<string, mixed>, current: array<string, mixed>}> */
    private array $changedSlots = [];

    protected function setUp(): void
    {
        if (self::seeds() === []) {
            self::markTestSkipped('REDIS_CLUSTER_SEEDS is not set — real-cluster tests are environment-gated.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->changedSlots === []) {
            return;
        }

        $masters = $this->masters();

        foreach ($masters as $master) {
            $this->admin($master)->execute('FLUSHDB');
        }

        foreach ($this->changedSlots as $slot => $owners) {
            foreach ($masters as $master) {
                $this->admin($master)->execute('CLUSTER', 'SETSLOT', $slot, 'STABLE');
            }

            if ($owners['current']['id'] !== $owners['original']['id']) {
                $this->moveEmptySlot($owners['current'], $owners['original'], $slot, $masters);
            }
        }

        $this->changedSlots = [];
    }

    public function test_keys_spanning_slots_round_trip_on_the_masters_that_own_them(): void
    {
        $cluster = $this->cluster();

        for ($i = 0; $i < 40; $i++) {
            $cluster->executeKeyed("spread-{$i}", 'SET', "spread-{$i}", "value-{$i}");
        }

        for ($i = 0; $i < 40; $i++) {
            self::assertSame("value-{$i}", $cluster->executeKeyed("spread-{$i}", 'GET', "spread-{$i}"));
        }

        $cluster->close();
    }

    public function test_nodes_reports_every_current_master(): void
    {
        $cluster = $this->cluster();
        $reported = [];

        foreach ($cluster->nodes() as $node) {
            self::assertInstanceOf(Client::class, $node);
            $reported[] = $node->endpoint->authority();
        }

        $expected = array_map(
            static fn (array $master): string => "{$master['host']}:{$master['port']}",
            $this->masters(),
        );

        sort($reported);
        sort($expected);
        self::assertSame($expected, $reported);

        $cluster->close();
    }

    public function test_a_slot_that_moved_under_a_client_is_followed_and_the_map_is_patched(): void
    {
        [$oldOwner, $newOwner] = $this->twoMasters();
        $slot = $oldOwner['ranges'][0][0] + self::SLOT_OFFSET_MOVED;
        $key = $this->keyInSlot($slot);

        $stale = $this->cluster();
        $stale->executeKeyed($key, 'GET', $key); // discovers the about-to-be-stale map

        $this->assignSlot($oldOwner, $newOwner, $slot);

        self::assertSame('MOVED', $this->redirectKindFor($oldOwner, $key));
        $stale->executeKeyed($key, 'SET', $key, 'moved-value');

        self::assertSame('moved-value', $this->admin($newOwner)->execute('GET', $key));
        // The patch means the second call goes straight to the new owner.
        self::assertSame('moved-value', $stale->executeKeyed($key, 'GET', $key));

        $stale->close();
    }

    public function test_a_migrating_key_is_read_through_ask_on_the_targets_own_connection(): void
    {
        [$source, $target] = $this->twoMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->keyInSlot($slot);

        $cluster = $this->cluster();
        $cluster->executeKeyed($key, 'SET', $key, 'ask-value');

        $this->beginMigration($source, $target, $slot, $key);
        self::assertSame('ASK', $this->redirectKindFor($source, $key));

        self::assertSame('ask-value', $cluster->executeKeyed($key, 'GET', $key));

        $cluster->close();
    }

    public function test_a_script_under_ask_reaches_the_migration_target(): void
    {
        [$source, $target] = $this->twoMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->keyInSlot($slot);

        $cluster = $this->cluster();
        $cluster->executeKeyed($key, 'SET', $key, 'ask-script-value');

        $this->beginMigration($source, $target, $slot, $key);
        self::assertSame('ASK', $this->redirectKindFor($source, $key));

        self::assertSame(
            'ask-script-value',
            $cluster->script($key, "return redis.call('GET', KEYS[1])", [$key]),
        );

        $cluster->close();
    }

    public function test_a_moved_then_ask_sequence_is_followed_inside_one_operation(): void
    {
        [$original, $intermediate, $final] = $this->threeMasters();
        $slot = $original['ranges'][0][0] + self::SLOT_OFFSET_SEQUENCE;
        $key = $this->keyInSlot($slot);

        $stale = $this->cluster();
        $stale->executeKeyed($key, 'GET', $key); // discovers $original as the owner

        $this->assignSlot($original, $intermediate, $slot);
        $this->admin($intermediate)->execute('SET', $key, 'sequence-value');
        $this->beginMigration($intermediate, $final, $slot, $key);

        self::assertSame('MOVED', $this->redirectKindFor($original, $key));
        self::assertSame('ASK', $this->redirectKindFor($intermediate, $key));

        self::assertSame('sequence-value', $stale->executeKeyed($key, 'GET', $key));

        $stale->close();
    }

    private function cluster(): ClusterClient
    {
        return ClusterClient::create(
            array_map(Endpoint::parse(...), self::seeds()),
            new ClientOptions(timeout: 10.0),
        );
    }

    /** @return list<string> */
    private static function seeds(): array
    {
        $raw = (string) getenv('REDIS_CLUSTER_SEEDS');

        return $raw === '' ? [] : array_map(trim(...), explode(',', $raw));
    }

    /**
     * Every master and the slot ranges it owns, ordered by first slot so
     * the same index names the same node across calls.
     *
     * @return list<array<string, mixed>>
     */
    private function masters(): array
    {
        $seed = Endpoint::parse(self::seeds()[0]);
        $reply = Client::create($seed, new ClientOptions(timeout: 10.0))->execute('CLUSTER', 'SLOTS');
        $byId = [];

        /** @var list<array{0: int, 1: int, 2: array{0: string, 1: int, 2: string}}> $reply */
        foreach ($reply as $entry) {
            [$start, $end, $node] = $entry;
            $id = $node[2];
            $byId[$id] ??= ['id' => $id, 'host' => $node[0], 'port' => $node[1], 'ranges' => []];
            $byId[$id]['ranges'][] = [$start, $end];
        }

        $masters = array_values($byId);
        usort($masters, static fn (array $a, array $b): int => $a['ranges'][0][0] <=> $b['ranges'][0][0]);

        return $masters;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function twoMasters(): array
    {
        $masters = $this->masters();

        if (count($masters) < 2) {
            self::markTestSkipped('Needs at least two masters to move a slot between nodes.');
        }

        return [$masters[0], $masters[1]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function threeMasters(): array
    {
        $masters = $this->masters();

        if (count($masters) < 3) {
            self::markTestSkipped('Needs at least three masters to follow MOVED and then ASK.');
        }

        return [$masters[0], $masters[1], $masters[2]];
    }

    /** @param array<string, mixed> $master */
    private function admin(array $master): Client
    {
        /** @var string $host */
        $host = $master['host'];
        /** @var int $port */
        $port = $master['port'];

        return Client::create(Endpoint::fromParts($host, $port), new ClientOptions(timeout: 10.0));
    }

    private function keyInSlot(int $slot): string
    {
        // One key in 16384 lands in a given slot, so this converges quickly.
        for ($i = 0; $i < 300_000; $i++) {
            $key = 'routed-' . bin2hex(random_bytes(6));

            if (HashSlot::calculate($key) === $slot) {
                return $key;
            }
        }

        throw new RuntimeException("Could not find a key hashing to slot {$slot}.");
    }

    /** @param array<string, mixed> $owner */
    private function redirectKindFor(array $owner, string $key): string
    {
        try {
            $this->admin($owner)->execute('GET', $key);
        } catch (QueryException $e) {
            return strtok($e->getMessage(), ' ') ?: '';
        }

        throw new RuntimeException('Expected a redirect reply, got a value.');
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function beginMigration(array $source, array $target, int $slot, string $key): void
    {
        $this->rememberSlot($source, $slot);

        $this->admin($target)->execute('CLUSTER', 'SETSLOT', $slot, 'IMPORTING', (string) $source['id']);
        $this->admin($source)->execute('CLUSTER', 'SETSLOT', $slot, 'MIGRATING', (string) $target['id']);
        $this->admin($source)->execute(
            'MIGRATE',
            (string) $target['host'],
            (int) $target['port'],
            $key,
            0,
            5000,
            'REPLACE',
        );
    }

    /**
     * @param array<string, mixed> $oldOwner
     * @param array<string, mixed> $newOwner
     */
    private function assignSlot(array $oldOwner, array $newOwner, int $slot): void
    {
        $this->rememberSlot($oldOwner, $slot);
        $this->moveEmptySlot($oldOwner, $newOwner, $slot);
        $this->changedSlots[$slot]['current'] = $newOwner;
    }

    /**
     * Redis's documented live-resharding sequence, with every master told
     * the outcome. SETSLOT ... NODE needs the source slot to hold no keys.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @param ?list<array<string, mixed>> $masters
     */
    private function moveEmptySlot(array $source, array $target, int $slot, ?array $masters = null): void
    {
        $masters ??= $this->masters();

        $this->admin($target)->execute('CLUSTER', 'SETSLOT', $slot, 'IMPORTING', (string) $source['id']);
        $this->admin($source)->execute('CLUSTER', 'SETSLOT', $slot, 'MIGRATING', (string) $target['id']);

        foreach ($masters as $master) {
            $this->admin($master)->execute('CLUSTER', 'SETSLOT', $slot, 'NODE', (string) $target['id']);
        }
    }

    /** @param array<string, mixed> $owner */
    private function rememberSlot(array $owner, int $slot): void
    {
        $this->changedSlots[$slot] ??= ['original' => $owner, 'current' => $owner];
    }
}
