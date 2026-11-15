<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Bedrock;

/**
 * Encodes `application/vnd.amazon.eventstream` frames for tests, string
 * headers only, as Bedrock sends them.
 */
final class EventStreamFrame
{
    /** @param array<array-key, mixed> $payload */
    public static function event(string $type, array $payload): string
    {
        return self::encode(
            [':event-type' => $type, ':content-type' => 'application/json', ':message-type' => 'event'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public static function exception(string $type, string $message): string
    {
        return self::encode(
            [':exception-type' => $type, ':content-type' => 'application/json', ':message-type' => 'exception'],
            json_encode(['message' => $message], JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, string> $headers */
    public static function encode(array $headers, string $payload): string
    {
        $headerBytes = '';

        foreach ($headers as $name => $value) {
            $headerBytes .= \chr(\strlen($name)) . $name . "\x07" . pack('n', \strlen($value)) . $value;
        }

        $total = 16 + \strlen($headerBytes) + \strlen($payload);
        $prelude = pack('NN', $total, \strlen($headerBytes));
        $message = $prelude . hash('crc32b', $prelude, true) . $headerBytes . $payload;

        return $message . hash('crc32b', $message, true);
    }
}
