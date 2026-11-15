<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Bedrock;

use Generator;
use JsonException;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Decodes the `application/vnd.amazon.eventstream` frames of a Bedrock
 * ConverseStream body as they arrive.
 *
 * A frame is total length (u32 BE), headers length (u32 BE), a CRC32 of
 * those eight bytes, the headers, the payload, and a CRC32 of everything
 * before it. Each frame is checked against both before anything in it is
 * used. A frame split across reads is completed by reading on; an end of
 * body inside a frame is a truncation.
 *
 * Every byte read from the body counts against $maxBytes, and a frame whose
 * declared length is larger than what remains of it is refused before it is
 * read. The caller owns the body and closes it.
 *
 * @internal
 */
final class EventStreamDecoder
{
    public const string TOO_LARGE = 'The Bedrock response is larger than its %d-byte limit.';

    private const int PRELUDE_BYTES = 12;

    private const int MINIMUM_FRAME_BYTES = 16;

    private const int READ_BYTES = 8192;

    private string $buffer = '';

    private int $consumed = 0;

    public function __construct(
        private readonly StreamInterface $body,
        private readonly int $maxBytes,
    ) {}

    /**
     * Each event as [event type, decoded JSON payload]. An `exception` or
     * `error` frame throws.
     *
     * @return Generator<int, array{string, array<array-key, mixed>}, mixed, void>
     *
     * @throws ProviderException
     */
    public function events(): Generator
    {
        while ($this->buffer !== '' || $this->fill(1)) {
            if (!$this->fill(self::PRELUDE_BYTES)) {
                throw self::truncated();
            }

            /** @var array{1: int, 2: int, 3: int} $prelude */
            $prelude = unpack('N3', $this->buffer);
            [, $total, $headersLength, $preludeCrc] = $prelude;

            if (self::crc(substr($this->buffer, 0, 8)) !== $preludeCrc) {
                throw new ProviderException('The Bedrock event stream failed its prelude checksum.');
            }

            if ($total < self::MINIMUM_FRAME_BYTES || $headersLength > $total - self::MINIMUM_FRAME_BYTES) {
                throw self::malformed();
            }

            // What was read before this frame began, and so what is left for it.
            if ($total > $this->maxBytes - ($this->consumed - \strlen($this->buffer))) {
                throw new ProviderException(\sprintf(self::TOO_LARGE, $this->maxBytes));
            }

            if (!$this->fill($total)) {
                throw self::truncated();
            }

            $frame = substr($this->buffer, 0, $total);
            $this->buffer = substr($this->buffer, $total);

            /** @var array{1: int} $messageCrc */
            $messageCrc = unpack('N', substr($frame, -4));

            if (self::crc(substr($frame, 0, -4)) !== $messageCrc[1]) {
                throw new ProviderException('The Bedrock event stream failed its message checksum.');
            }

            $headers = self::headers(substr($frame, self::PRELUDE_BYTES, $headersLength));
            $payload = substr($frame, self::PRELUDE_BYTES + $headersLength, $total - self::MINIMUM_FRAME_BYTES - $headersLength);

            yield self::event($headers, $payload);
        }
    }

    /**
     * Reads until the buffer holds $bytes, or answers false at the end of
     * the body.
     *
     * @throws ProviderException
     */
    private function fill(int $bytes): bool
    {
        while (\strlen($this->buffer) < $bytes) {
            if ($this->body->eof()) {
                return false;
            }

            try {
                $chunk = $this->body->read(self::READ_BYTES);
            } catch (RuntimeException $e) {
                throw new ProviderException('The Bedrock response could not be read.', previous: $e);
            }

            $this->consumed += \strlen($chunk);

            if ($this->consumed > $this->maxBytes) {
                throw new ProviderException(\sprintf(self::TOO_LARGE, $this->maxBytes));
            }

            $this->buffer .= $chunk;
        }

        return true;
    }

    /**
     * Header values of types 0-9; only strings are kept.
     *
     * @return array<string, string>
     *
     * @throws ProviderException
     */
    private static function headers(string $bytes): array
    {
        $headers = [];
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $nameLength = \ord($bytes[$offset]);
            $name = self::slice($bytes, $offset + 1, $nameLength);
            $offset += 1 + $nameLength;

            $type = \ord(self::slice($bytes, $offset, 1));
            ++$offset;

            $valueLength = match ($type) {
                0, 1 => 0,
                2 => 1,
                3 => 2,
                4 => 4,
                5, 8 => 8,
                9 => 16,
                6, 7 => 2 + self::u16(self::slice($bytes, $offset, 2)),
                default => throw self::malformed(),
            };
            $value = self::slice($bytes, $offset, $valueLength);
            $offset += $valueLength;

            if ($type === 7) {
                $headers[$name] = substr($value, 2);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{string, array<array-key, mixed>}
     *
     * @throws ProviderException
     */
    private static function event(array $headers, string $payload): array
    {
        $messageType = $headers[':message-type'] ?? '';

        if ($messageType === 'error') {
            throw new ProviderException(\sprintf(
                'Bedrock ended the stream with error %s: %s',
                $headers[':error-code'] ?? 'Unknown',
                $headers[':error-message'] ?? '',
            ));
        }

        $decoded = self::json($payload);

        if ($messageType === 'exception') {
            $message = $decoded['message'] ?? $decoded['Message'] ?? $payload;

            throw new ProviderException(\sprintf(
                'Bedrock ended the stream with %s: %s',
                $headers[':exception-type'] ?? 'Unknown',
                \is_string($message) ? $message : $payload,
            ));
        }

        $eventType = $headers[':event-type'] ?? '';

        if ($messageType !== 'event' || $eventType === '') {
            throw self::malformed();
        }

        return [$eventType, $decoded];
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ProviderException
     */
    private static function json(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException('The Bedrock event stream carried a payload that is not JSON.', previous: $e);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /** @throws ProviderException */
    private static function slice(string $bytes, int $offset, int $length): string
    {
        if ($offset + $length > \strlen($bytes)) {
            throw self::malformed();
        }

        return substr($bytes, $offset, $length);
    }

    private static function u16(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('n', $bytes);

        return $value[1];
    }

    private static function crc(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', hash('crc32b', $bytes, true));

        return $value[1];
    }

    private static function truncated(): ProviderException
    {
        return new ProviderException('The Bedrock event stream was truncated.');
    }

    private static function malformed(): ProviderException
    {
        return new ProviderException('The Bedrock event stream carried a malformed frame.');
    }
}
