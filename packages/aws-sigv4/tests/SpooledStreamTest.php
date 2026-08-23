<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Kinetis\AwsSigV4\SpooledStream;
use PHPUnit\Framework\TestCase;

final class SpooledStreamTest extends TestCase
{
    public function test_constructed_from_a_string_reads_back_the_same_content(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame('hello world', $stream->getContents());
    }

    public function test_is_positioned_at_the_start_immediately_after_construction(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame(0, $stream->tell());
    }

    public function test_to_string_rewinds_first_so_it_always_returns_the_full_content(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->read(5);

        self::assertSame('hello world', (string) $stream);
    }

    public function test_get_contents_reads_from_the_current_position_not_the_start(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->read(6);

        self::assertSame('world', $stream->getContents());
    }

    public function test_read_advances_the_position_by_the_number_of_bytes_actually_read(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame('hello', $stream->read(5));
        self::assertSame(5, $stream->tell());
    }

    /**
     * fread() itself raises a ValueError for a length below 1 — a real
     * crash a naive pass-through would hit for a legitimate 0-length
     * read, which StreamInterface::read() documents as returning an
     * empty string rather than being an error.
     */
    public function test_reading_zero_bytes_returns_an_empty_string_rather_than_erroring(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame('', $stream->read(0));
    }

    public function test_reading_a_negative_length_returns_an_empty_string_rather_than_erroring(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame('', $stream->read(-1));
    }

    public function test_is_always_seekable_regardless_of_the_original_streams_own_seekability(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertTrue($stream->isSeekable());
    }

    public function test_rewind_returns_the_position_to_the_start_after_reading(): void
    {
        $stream = new SpooledStream('hello world');
        // PHP's own feof() only flips true after a read attempt past
        // the end fails to return data -- reaching the exact last byte
        // via a successful read doesn't set it yet, so this reads one
        // byte past the content to genuinely reach eof() before
        // rewinding.
        $stream->read(12);
        self::assertTrue($stream->eof());

        $stream->rewind();

        self::assertSame(0, $stream->tell());
        self::assertFalse($stream->eof());
        self::assertSame('hello world', $stream->getContents());
    }

    public function test_seek_moves_to_an_arbitrary_offset(): void
    {
        $stream = new SpooledStream('hello world');

        $stream->seek(6);

        self::assertSame('world', $stream->getContents());
    }

    public function test_is_readable_and_writable(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
    }

    public function test_write_appends_at_the_current_position(): void
    {
        $stream = new SpooledStream('hello');
        $stream->seek(0, SEEK_END);

        $stream->write(' world');

        self::assertSame(11, $stream->getSize());
        $stream->rewind();
        self::assertSame('hello world', $stream->getContents());
    }

    public function test_get_size_reports_the_byte_length(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertSame(11, $stream->getSize());
    }

    public function test_get_metadata_without_a_key_returns_the_full_array(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertIsArray($stream->getMetadata());
    }

    public function test_get_metadata_with_an_unknown_key_returns_null(): void
    {
        $stream = new SpooledStream('hello world');

        self::assertNull($stream->getMetadata('not-a-real-key'));
    }

    public function test_detach_returns_the_underlying_resource(): void
    {
        $stream = new SpooledStream('hello world');

        $resource = $stream->detach();

        self::assertIsResource($resource);
    }
}
