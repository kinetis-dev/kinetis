<?php

declare(strict_types=1);

namespace Kinetis\Redis\Cluster;

use Kinetis\Redis\Endpoint;
use Kinetis\Redis\Exception\TopologyUnavailable;

/**
 * Which master owns which slot, read from CLUSTER SLOTS.
 *
 * CLUSTER SLOTS is the only topology command this package sends. Its
 * reply is a flat list of `[start, end, [host, port, id, ...], ...replicas]`
 * served by every Redis-compatible cluster from 3.0 onward, and the
 * first node of an entry is its master. The parser accepts a reply only
 * when the ranges cover 0-16383 exactly once, so a partially-formed
 * cluster is refused rather than routed against.
 *
 * A MOVED reply patches the single slot it names through assign(). The
 * patch lives until the next discovery replaces the whole map; a
 * discovery that has not caught up yet costs one more MOVED on a later
 * operation, bounded by the redirect limit.
 */
final class SlotMap
{
    /** @var array<int, Endpoint> */
    private array $patched = [];

    /** @param non-empty-list<array{start: int, end: int, master: Endpoint}> $ranges */
    private function __construct(private readonly array $ranges) {}

    /**
     * @param mixed $reply the CLUSTER SLOTS reply
     * @param Endpoint $source the node that answered, substituted for
     *        the empty host Redis reports when it announces no address
     *        of its own
     */
    public static function parse(mixed $reply, Endpoint $source): self
    {
        if (!is_array($reply) || $reply === []) {
            throw new TopologyUnavailable('CLUSTER SLOTS returned no slot ranges.');
        }

        $ranges = [];

        foreach ($reply as $entry) {
            if (!is_array($entry) || !is_int($entry[0] ?? null) || !is_int($entry[1] ?? null) || !is_array($entry[2] ?? null)) {
                throw new TopologyUnavailable('CLUSTER SLOTS returned a range that is not [start, end, master].');
            }

            /** @var array{0: int, 1: int, 2: array<int, mixed>} $entry */
            $host = $entry[2][0] ?? null;
            $port = $entry[2][1] ?? null;

            if (!is_string($host) || !is_int($port)) {
                throw new TopologyUnavailable('CLUSTER SLOTS returned a master without a host and port.');
            }

            try {
                $master = Endpoint::fromParts($host === '' ? $source->host : $host, $port);
            } catch (\InvalidArgumentException $e) {
                throw new TopologyUnavailable('CLUSTER SLOTS returned a master this client cannot reach: ' . $e->getMessage(), 0, $e);
            }

            $ranges[] = ['start' => $entry[0], 'end' => $entry[1], 'master' => $master];
        }

        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $expected = 0;

        foreach ($ranges as $range) {
            if ($range['start'] !== $expected || $range['end'] < $range['start']) {
                throw new TopologyUnavailable(
                    "CLUSTER SLOTS does not cover slot {$expected}: the next range starts at {$range['start']}.",
                );
            }

            $expected = $range['end'] + 1;
        }

        if ($expected !== HashSlot::COUNT) {
            throw new TopologyUnavailable("CLUSTER SLOTS covers {$expected} of " . HashSlot::COUNT . ' slots.');
        }

        /** @var non-empty-list<array{start: int, end: int, master: Endpoint}> $ranges */
        return new self($ranges);
    }

    public function endpointForSlot(int $slot): Endpoint
    {
        if (isset($this->patched[$slot])) {
            return $this->patched[$slot];
        }

        foreach ($this->ranges as $range) {
            if ($slot >= $range['start'] && $slot <= $range['end']) {
                return $range['master'];
            }
        }

        throw new TopologyUnavailable("No master owns slot {$slot}.");
    }

    public function assign(int $slot, Endpoint $endpoint): void
    {
        $this->patched[$slot] = $endpoint;
    }

    /** @return non-empty-list<Endpoint> deduplicated by authority */
    public function masters(): array
    {
        $masters = [];

        foreach ($this->ranges as $range) {
            $masters[$range['master']->authority()] = $range['master'];
        }

        /** @var non-empty-list<Endpoint> */
        return array_values($masters);
    }
}
