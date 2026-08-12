<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

/**
 * Redis Cluster's own slot-assignment algorithm: CRC16 (the XMODEM/CCITT
 * variant, polynomial 0x1021) of a key, mod 16384. When a key contains a
 * "{...}" hash tag, only the substring inside the braces is hashed, so
 * multiple keys sharing the same tag land on the same slot — irrelevant
 * for a plain PSR-16 cache key (PSR-16 itself forbids "{"/"}" in a key),
 * but part of the algorithm regardless.
 */
final class Crc16
{
    public static function slotFor(string $key): int
    {
        return self::compute(self::hashTag($key)) % 16384;
    }

    private static function hashTag(string $key): string
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

    private static function compute(string $data): int
    {
        $crc = 0x0000;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($data[$i]) << 8);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
