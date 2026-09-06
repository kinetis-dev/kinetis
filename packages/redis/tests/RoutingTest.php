<?php

declare(strict_types=1);

namespace Kinetis\Redis\Tests;

use Amp\Redis\Protocol\ProtocolException;
use InvalidArgumentException;
use Kinetis\Redis\Cluster\HashSlot;
use Kinetis\Redis\Cluster\Redirect;
use Kinetis\Redis\Cluster\RedirectKind;
use Kinetis\Redis\Cluster\SlotMap;
use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\TopologyUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function hashSlotVectors(): iterable
    {
        // CRC-16/XMODEM's own check value, then Redis's own documented
        // example, then the hash-tag rule.
        yield 'the algorithm check vector' => ['123456789', 12739];
        yield 'plain key' => ['foo', 12182];
        yield 'hash tag selects the tagged part' => ['{user1000}.following', 3443];
        yield 'the same tag lands on the same slot' => ['{user1000}.followers', 3443];
        yield 'an empty tag hashes the whole key' => ['{}foo', HashSlot::calculate('{}foo')];
        yield 'an unclosed brace hashes the whole key' => ['{foo', HashSlot::calculate('{foo')];
        yield 'an empty key is slot 0' => ['', 0];
    }

    #[Test]
    #[DataProvider('hashSlotVectors')]
    public function hash_slots_follow_the_cluster_specification(string $key, int $slot): void
    {
        self::assertSame($slot, HashSlot::calculate($key));
    }

    #[Test]
    public function an_empty_hash_tag_is_not_treated_as_a_tag(): void
    {
        self::assertSame(HashSlot::calculate('{}foo'), HashSlot::calculate('{}foo'));
        self::assertNotSame(HashSlot::calculate('{}foo'), HashSlot::calculate(''));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function endpoints(): iterable
    {
        yield 'IPv4' => ['10.0.0.1:6379', '10.0.0.1', 6379];
        yield 'hostname' => ['redis.internal:6380', 'redis.internal', 6380];
        yield 'bracketed IPv6' => ['[2001:db8::10]:6379', '2001:db8::10', 6379];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function an_endpoint_parses_into_a_host_and_a_port(string $address, string $host, int $port): void
    {
        $endpoint = Endpoint::parse($address);

        self::assertSame($host, $endpoint->host);
        self::assertSame($port, $endpoint->port);
        self::assertSame($address, $endpoint->authority());
        self::assertSame("tcp://{$address}", $endpoint->toUri());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEndpoints(): iterable
    {
        yield 'unbracketed IPv6' => ['2001:db8::10:6379'];
        yield 'no port' => ['10.0.0.1'];
        yield 'empty host' => [':6379'];
        yield 'non-numeric port' => ['10.0.0.1:redis'];
        yield 'port above the range' => ['10.0.0.1:70000'];
        yield 'port zero' => ['10.0.0.1:0'];
        yield 'unterminated bracket' => ['[2001:db8::10:6379'];
        yield 'bracket without a port' => ['[2001:db8::10]'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('malformedEndpoints')]
    public function a_malformed_endpoint_is_rejected_rather_than_guessed_at(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        Endpoint::parse($address);
    }

    #[Test]
    public function an_endpoint_built_from_separate_parts_is_validated_the_same_way(): void
    {
        $endpoint = Endpoint::fromParts('10.0.0.1', 6379);

        self::assertSame('10.0.0.1', $endpoint->host);
        self::assertSame(6379, $endpoint->port);

        $this->expectException(InvalidArgumentException::class);

        Endpoint::fromParts('10.0.0.1', 0);
    }

    #[Test]
    public function a_master_the_client_cannot_reach_is_a_topology_failure(): void
    {
        $this->expectException(TopologyUnavailable::class);

        SlotMap::parse([[0, 16383, ['10.0.0.1', 0, 'id']]], Endpoint::parse('10.0.0.9:6379'));
    }

    #[Test]
    public function a_redirect_reply_parses_into_a_kind_a_slot_and_a_target(): void
    {
        $moved = Redirect::tryParse('MOVED 3999 10.0.0.2:6379');

        self::assertSame(RedirectKind::Moved, $moved?->kind);
        self::assertSame(3999, $moved->slot);
        self::assertSame('10.0.0.2:6379', $moved->target->authority());

        $ask = Redirect::tryParse('ASK 42 [2001:db8::10]:6379');

        self::assertSame(RedirectKind::Ask, $ask?->kind);
        self::assertSame('[2001:db8::10]:6379', $ask->target->authority());
    }

    #[Test]
    public function an_error_that_is_not_a_redirect_comes_back_as_null(): void
    {
        self::assertNull(Redirect::tryParse('WRONGTYPE Operation against a key holding the wrong kind of value'));
        self::assertNull(Redirect::tryParse('CROSSSLOT Keys in request don\'t hash to the same slot'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRedirects(): iterable
    {
        yield 'missing target' => ['MOVED 3999'];
        yield 'non-numeric slot' => ['ASK slot 10.0.0.2:6379'];
        yield 'slot out of range' => ['MOVED 16384 10.0.0.2:6379'];
        yield 'malformed target' => ['MOVED 3999 10.0.0.2'];
    }

    #[Test]
    #[DataProvider('malformedRedirects')]
    public function a_redirect_naming_a_kind_it_cannot_honour_is_a_protocol_failure(string $message): void
    {
        $this->expectException(ProtocolException::class);

        Redirect::tryParse($message);
    }

    #[Test]
    public function a_complete_slots_reply_maps_every_slot_to_its_master(): void
    {
        $map = SlotMap::parse([
            [8192, 16383, ['10.0.0.2', 6379, 'b']],
            [0, 8191, ['10.0.0.1', 6379, 'a']],
        ], Endpoint::parse('10.0.0.1:6379'));

        self::assertSame('10.0.0.1:6379', $map->endpointForSlot(0)->authority());
        self::assertSame('10.0.0.1:6379', $map->endpointForSlot(8191)->authority());
        self::assertSame('10.0.0.2:6379', $map->endpointForSlot(8192)->authority());
        self::assertSame('10.0.0.2:6379', $map->endpointForSlot(16383)->authority());
        self::assertSame(
            ['10.0.0.1:6379', '10.0.0.2:6379'],
            array_map(static fn (Endpoint $e): string => $e->authority(), $map->masters()),
        );
    }

    #[Test]
    public function a_master_reported_without_an_address_is_the_node_that_answered(): void
    {
        $map = SlotMap::parse([[0, 16383, ['', 6379, 'a']]], Endpoint::parse('10.0.0.9:7000'));

        self::assertSame('10.0.0.9:6379', $map->endpointForSlot(1)->authority());
    }

    #[Test]
    public function one_master_serving_two_ranges_is_listed_once(): void
    {
        $map = SlotMap::parse([
            [0, 100, ['10.0.0.1', 6379, 'a']],
            [101, 16383, ['10.0.0.1', 6379, 'a']],
        ], Endpoint::parse('10.0.0.1:6379'));

        self::assertCount(1, $map->masters());
    }

    #[Test]
    public function a_patched_slot_wins_over_the_range_that_contains_it(): void
    {
        $map = SlotMap::parse([[0, 16383, ['10.0.0.1', 6379, 'a']]], Endpoint::parse('10.0.0.1:6379'));
        $map->assign(7629, Endpoint::parse('10.0.0.5:6379'));

        self::assertSame('10.0.0.5:6379', $map->endpointForSlot(7629)->authority());
        self::assertSame('10.0.0.1:6379', $map->endpointForSlot(7630)->authority());
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableSlotReplies(): iterable
    {
        yield 'empty' => [[]];
        yield 'not a list' => ['OK'];
        yield 'gap' => [[[0, 8191, ['10.0.0.1', 6379]], [8193, 16383, ['10.0.0.2', 6379]]]];
        yield 'overlap' => [[[0, 8191, ['10.0.0.1', 6379]], [8000, 16383, ['10.0.0.2', 6379]]]];
        yield 'short of the last slot' => [[[0, 16382, ['10.0.0.1', 6379]]]];
        yield 'master without a port' => [[[0, 16383, ['10.0.0.1']]]];
        yield 'range that is not a triple' => [[[0, 16383]]];
    }

    #[Test]
    #[DataProvider('unusableSlotReplies')]
    public function a_slots_reply_that_does_not_cover_the_keyspace_exactly_once_is_refused(mixed $reply): void
    {
        $this->expectException(TopologyUnavailable::class);

        SlotMap::parse($reply, Endpoint::parse('10.0.0.1:6379'));
    }
}
