<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Closure;
use Kinetis\AwsSigV4\Exception\StreamException;
use Kinetis\AwsSigV4\SpooledStream;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_body_beyond_php_temps_in_memory_threshold_round_trips_intact(): void
    {
        // php://temp spills to a real temp file past ~2MB — this proves
        // the constructor's own write loop delivers the full content
        // through that boundary, not just for a small in-memory body.
        $large = str_repeat('a', 3 * 1024 * 1024);
        $stream = new SpooledStream($large);

        self::assertSame(strlen($large), $stream->getSize());
        self::assertSame($large, $stream->getContents());
    }

    // --- close()/detach() idempotency and post-detach behavior ---

    public function test_close_is_safe_to_call_twice(): void
    {
        $stream = new SpooledStream('hello world');

        $stream->close();
        $stream->close();

        self::addToAssertionCount(1);
    }

    public function test_detach_is_safe_to_call_twice_and_the_second_call_returns_null(): void
    {
        $stream = new SpooledStream('hello world');

        $first = $stream->detach();
        $second = $stream->detach();

        self::assertIsResource($first);
        self::assertNull($second);
    }

    public function test_detach_after_close_returns_null(): void
    {
        $stream = new SpooledStream('hello world');

        $stream->close();

        self::assertNull($stream->detach());
    }

    public function test_close_after_detach_does_not_close_the_already_detached_resource(): void
    {
        $stream = new SpooledStream('hello world');

        $resource = $stream->detach();
        \assert(\is_resource($resource));
        $stream->close();

        // The caller now owns $resource — close() must not have touched
        // it, since it was already handed off by detach().
        self::assertTrue(\is_resource($resource));
        \fclose($resource);
    }

    public function test_get_size_is_null_after_close(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->close();

        self::assertNull($stream->getSize());
    }

    public function test_get_size_is_null_after_detach(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->detach();

        self::assertNull($stream->getSize());
    }

    public function test_capability_methods_are_false_after_close(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->close();

        self::assertFalse($stream->isSeekable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isReadable());
    }

    public function test_capability_methods_are_false_after_detach(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->detach();

        self::assertFalse($stream->isSeekable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isReadable());
    }

    public function test_get_metadata_after_detach_reports_nothing_rather_than_throwing(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->detach();

        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));
    }

    public function test_eof_is_true_after_detach(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->detach();

        self::assertTrue($stream->eof());
    }

    public static function operationalMethodsAfterDetachProvider(): iterable
    {
        yield 'tell' => [static fn (SpooledStream $s) => $s->tell()];
        yield 'seek' => [static fn (SpooledStream $s) => $s->seek(0)];
        yield 'rewind' => [static fn (SpooledStream $s) => $s->rewind()];
        yield 'write' => [static fn (SpooledStream $s) => $s->write('x')];
        yield 'read' => [static fn (SpooledStream $s) => $s->read(1)];
        // Zero/negative lengths must still throw once detached — the
        // resource check has to run before the "nothing was asked for"
        // short-circuit, not after it, or these two would silently
        // return '' instead.
        yield 'read zero length' => [static fn (SpooledStream $s) => $s->read(0)];
        yield 'read negative length' => [static fn (SpooledStream $s) => $s->read(-1)];
        yield 'getContents' => [static fn (SpooledStream $s) => $s->getContents()];
    }

    /**
     * Every operational method must throw this package's own
     * StreamException once the resource is gone — never a native
     * TypeError/Error/warning from touching a null property.
     *
     * @param Closure(SpooledStream): mixed $operation
     */
    #[DataProvider('operationalMethodsAfterDetachProvider')]
    public function test_operational_methods_throw_the_package_exception_after_detach(Closure $operation): void
    {
        $stream = new SpooledStream('hello world');
        $stream->detach();

        $this->expectException(StreamException::class);
        $operation($stream);
    }

    public function test_to_string_never_throws_after_close(): void
    {
        $stream = new SpooledStream('hello world');
        $stream->close();

        self::assertSame('', (string) $stream);
    }

    public function test_to_string_never_throws_after_detach(): void
    {
        $stream = new SpooledStream('hello world');
        $resource = $stream->detach();
        \assert(\is_resource($resource));

        self::assertSame('', (string) $stream);

        \fclose($resource);
    }
}
