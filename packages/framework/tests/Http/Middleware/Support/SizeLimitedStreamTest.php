<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware\Support;

use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Middleware\Support\SizeLimitedStream;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;

final class SizeLimitedStreamTest extends TestCase
{
    public function test_reading_under_the_limit_returns_the_real_contents(): void
    {
        $stream = new SizeLimitedStream(Stream::create('hello'), maxBytes: 10);

        self::assertSame('hello', $stream->getContents());
    }

    public function test_get_contents_throws_once_the_running_total_exceeds_the_limit(): void
    {
        $stream = new SizeLimitedStream(Stream::create(str_repeat('x', 20)), maxBytes: 10);

        $this->expectException(BodyTooLargeException::class);

        $stream->getContents();
    }

    public function test_read_throws_once_the_running_total_exceeds_the_limit(): void
    {
        $stream = new SizeLimitedStream(Stream::create(str_repeat('x', 20)), maxBytes: 10);

        $this->expectException(BodyTooLargeException::class);

        $stream->read(20);
    }

    public function test_to_string_reports_an_empty_string_instead_of_throwing(): void
    {
        // StreamInterface::__toString() is required to never throw —
        // getContents() is the one that actually enforces the cap.
        $stream = new SizeLimitedStream(Stream::create(str_repeat('x', 20)), maxBytes: 10);

        self::assertSame('', (string) $stream);
    }

    public function test_to_string_returns_the_real_contents_under_the_limit(): void
    {
        $stream = new SizeLimitedStream(Stream::create('hello'), maxBytes: 10);

        self::assertSame('hello', (string) $stream);
    }

    public function test_multiple_reads_accumulate_toward_the_same_limit(): void
    {
        $stream = new SizeLimitedStream(Stream::create(str_repeat('x', 15)), maxBytes: 10);

        self::assertSame(str_repeat('x', 10), $stream->read(10));

        $this->expectException(BodyTooLargeException::class);

        $stream->read(5);
    }

    public function test_rewind_resets_the_running_total(): void
    {
        $stream = new SizeLimitedStream(Stream::create('hello'), maxBytes: 10);

        $stream->read(5);
        $stream->rewind();

        self::assertSame('hello', $stream->getContents());
    }

    public function test_other_methods_delegate_to_the_wrapped_stream(): void
    {
        $stream = new SizeLimitedStream(Stream::create('hello'), maxBytes: 100);

        self::assertSame(5, $stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isSeekable());
        self::assertFalse($stream->eof());
    }
}
