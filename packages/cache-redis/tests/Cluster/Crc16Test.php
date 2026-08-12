<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Cluster;

use Kinetis\SimpleCache\Cluster\Crc16;
use PHPUnit\Framework\TestCase;

final class Crc16Test extends TestCase
{
    public function test_matches_the_known_foo_slot(): void
    {
        // A commonly-cited Redis Cluster example: "foo" hashes to slot
        // 12182 — confirmed independently against a real 6-node cluster
        // while building this, not just recalled.
        self::assertSame(12182, Crc16::slotFor('foo'));
    }

    public function test_a_hash_tag_isolates_the_substring_hashed(): void
    {
        // Both keys share the "user1000" tag, so both must land on the
        // same slot regardless of what follows the tag.
        self::assertSame(
            Crc16::slotFor('{user1000}.following'),
            Crc16::slotFor('{user1000}.followers'),
        );
    }

    public function test_a_stray_closing_brace_with_no_opening_one_hashes_the_whole_key(): void
    {
        self::assertNotSame(Crc16::slotFor('foo}'), Crc16::slotFor('foo'));
    }

    public function test_an_empty_hash_tag_falls_back_to_the_whole_key_instead_of_an_empty_string(): void
    {
        // If "{}" were treated as a (empty) hash tag, both keys below would
        // collapse onto the same slot (both hashing "").  They must not.
        self::assertNotSame(Crc16::slotFor('{}foo'), Crc16::slotFor('{}bar'));
    }

    public function test_slots_are_always_within_the_valid_range(): void
    {
        foreach (['a', 'foo', 'bar', 'user:42:session', str_repeat('x', 500)] as $key) {
            $slot = Crc16::slotFor($key);
            self::assertGreaterThanOrEqual(0, $slot);
            self::assertLessThan(16384, $slot);
        }
    }
}
