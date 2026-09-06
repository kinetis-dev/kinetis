<?php

declare(strict_types=1);

namespace Kinetis\Redis\Cluster;

/**
 * Redis Cluster's own slot assignment: CRC16 (the XMODEM variant,
 * polynomial 0x1021) of the key, modulo 16384.
 *
 * A key containing a "{...}" hash tag hashes only the substring inside
 * the braces, so keys sharing a tag share a slot. An empty tag ("{}")
 * and an unclosed brace both fall back to hashing the whole key, which
 * is what the Redis specification requires.
 */
final class HashSlot
{
    public const int COUNT = 16384;

    public static function calculate(string $key): int
    {
        return self::crc16(self::tag($key)) % self::COUNT;
    }

    private static function tag(string $key): string
    {
        $start = strpos($key, '{');

        if ($start === false) {
            return $key;
        }

        $end = strpos($key, '}', $start + 1);

        if ($end === false || $end === $start + 1) {
            return $key;
        }

        return substr($key, $start + 1, $end - $start - 1);
    }

    private static function crc16(string $data): int
    {
        $crc = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
