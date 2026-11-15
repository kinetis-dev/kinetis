<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Bedrock;

use Kinetis\Neuron\Bedrock\EventStreamDecoder;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\ChunkedStream;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\EventStreamFrame;
use NeuronAI\Exceptions\ProviderException;
use PHPUnit\Framework\TestCase;

final class EventStreamDecoderTest extends TestCase
{
    private const int MAX = 1024 * 1024;

    public function test_a_frame_decodes_to_its_event_type_and_payload(): void
    {
        $frame = EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]);

        self::assertSame(
            [['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]]],
            self::decode($frame),
        );
    }

    public function test_every_header_value_type_is_skipped_by_its_length(): void
    {
        $headers = "\x04flag\x00"                             // true
            . "\x05flag2\x01"                                 // false
            . "\x04byte\x02\x7f"                              // int8
            . "\x05short\x03\x00\x01"                         // int16
            . "\x03int\x04\x00\x00\x00\x01"                   // int32
            . "\x04long\x05" . str_repeat("\x00", 8)          // int64
            . "\x05bytes\x06\x00\x03abc"                      // bytes
            . "\x04time\x08" . str_repeat("\x00", 8)          // timestamp
            . "\x04uuid\x09" . str_repeat("\x11", 16)         // uuid
            . "\x0b:event-type\x07\x00\x0bmessageStop"
            . "\x0d:message-type\x07\x00\x05event";
        $payload = '{"stopReason":"end_turn"}';
        $total = 16 + \strlen($headers) + \strlen($payload);
        $prelude = pack('NN', $total, \strlen($headers));
        $message = $prelude . hash('crc32b', $prelude, true) . $headers . $payload;

        self::assertSame(
            [['messageStop', ['stopReason' => 'end_turn']]],
            self::decode($message . hash('crc32b', $message, true)),
        );
    }

    public function test_the_published_empty_message_vector_passes_both_checksums(): void
    {
        // AWS's event-stream test vector `empty_message`: valid on the wire,
        // refused here only because it names no message type.
        $this->expectExceptionObject(new ProviderException('The Bedrock event stream carried a malformed frame.'));

        self::decode(hex2bin('000000100000000005c248eb7d98c8ff'));
    }

    public function test_frames_split_across_reads_at_every_offset_decode_whole(): void
    {
        $first = EventStreamFrame::event('contentBlockDelta', ['delta' => ['text' => 'Hel']]);
        $second = EventStreamFrame::event('contentBlockDelta', ['delta' => ['text' => 'lo']]);
        $bytes = $first . $second;

        for ($split = 1; $split < \strlen($bytes); ++$split) {
            $events = iterator_to_array(
                new EventStreamDecoder(ChunkedStream::of($bytes, $split, min($split + 5, \strlen($bytes))), self::MAX)->events(),
                false,
            );

            self::assertSame(['Hel', 'lo'], array_map(static fn (array $event): string => $event[1]['delta']['text'], $events), "split at {$split}");
        }
    }

    public function test_a_flipped_payload_byte_fails_the_message_checksum(): void
    {
        $frame = EventStreamFrame::event('contentBlockDelta', ['delta' => ['text' => 'Hi']]);
        $frame[\strlen($frame) - 8] = $frame[\strlen($frame) - 8] === 'x' ? 'y' : 'x';

        $this->expectExceptionObject(new ProviderException('The Bedrock event stream failed its message checksum.'));

        self::decode($frame);
    }

    public function test_a_bad_prelude_checksum_is_refused_before_the_headers_are_read(): void
    {
        // Header type 99 would be a malformed frame if the headers were parsed.
        $frame = EventStreamFrame::encode([], '');
        $frame = substr($frame, 0, 8) . "\x00\x00\x00\x00" . "\x01a\x63";

        $this->expectExceptionObject(new ProviderException('The Bedrock event stream failed its prelude checksum.'));

        self::decode($frame);
    }

    public function test_an_end_of_body_inside_a_frame_is_a_truncation(): void
    {
        $frame = EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn']);

        foreach ([5, 12, \strlen($frame) - 1] as $cut) {
            try {
                self::decode(EventStreamFrame::event('messageStart', ['role' => 'assistant']) . substr($frame, 0, $cut));
                self::fail("A body cut at {$cut} decoded.");
            } catch (ProviderException $e) {
                self::assertSame('The Bedrock event stream was truncated.', $e->getMessage());
            }
        }
    }

    public function test_an_exception_frame_throws_with_its_type_and_message(): void
    {
        $this->expectExceptionObject(new ProviderException(
            'Bedrock ended the stream with throttlingException: Too many requests',
        ));

        self::decode(
            EventStreamFrame::event('messageStart', ['role' => 'assistant'])
            . EventStreamFrame::exception('throttlingException', 'Too many requests'),
        );
    }

    public function test_an_error_frame_throws_with_its_code_and_message(): void
    {
        $this->expectExceptionObject(new ProviderException(
            'Bedrock ended the stream with error InternalFailure: Try again',
        ));

        self::decode(EventStreamFrame::encode(
            [':message-type' => 'error', ':error-code' => 'InternalFailure', ':error-message' => 'Try again'],
            '',
        ));
    }

    public function test_a_body_exactly_at_the_ceiling_decodes(): void
    {
        $frame = EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn']);

        self::assertCount(1, iterator_to_array(new EventStreamDecoder(ChunkedStream::of($frame), \strlen($frame))->events()));
    }

    public function test_one_byte_past_the_ceiling_is_refused(): void
    {
        $frames = EventStreamFrame::event('messageStart', ['role' => 'assistant'])
            . EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn']);
        $max = \strlen($frames) - 1;

        $this->expectExceptionObject(new ProviderException(\sprintf(EventStreamDecoder::TOO_LARGE, $max)));

        iterator_to_array(new EventStreamDecoder(ChunkedStream::of($frames), $max)->events());
    }

    public function test_a_frame_declaring_more_than_the_remaining_allowance_is_refused_before_it_is_read(): void
    {
        $prelude = pack('NN', 1_000_000, 0);
        $body = new ChunkedStream([$prelude . hash('crc32b', $prelude, true), str_repeat('x', 4096), str_repeat('x', 4096)]);

        try {
            iterator_to_array(new EventStreamDecoder($body, 64 * 1024)->events());
            self::fail('The oversized frame decoded.');
        } catch (ProviderException $e) {
            self::assertSame(\sprintf(EventStreamDecoder::TOO_LARGE, 64 * 1024), $e->getMessage());
        }

        self::assertSame(12, $body->bytesRead);
    }

    /** @return list<array{string, array<array-key, mixed>}> */
    private static function decode(string $bytes): array
    {
        return iterator_to_array(new EventStreamDecoder(ChunkedStream::of($bytes), self::MAX)->events(), false);
    }
}
