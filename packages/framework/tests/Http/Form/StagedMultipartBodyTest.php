<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\FormStagingException;
use Kinetis\Http\Form\StagedMultipartBody;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The staging seam, driven through a stream that fails exactly where a
 * real one occasionally does. Each of these failures, unhandled,
 * produces a shorter multipart body — and a shorter multipart body still
 * parses, into a form that looks complete and has had its tail removed.
 * That is why they are tested here rather than left to the adapters,
 * where the same three cases would have to be reproduced twice.
 */
final class StagedMultipartBodyTest extends TestCase
{
    protected function setUp(): void
    {
        FailingStream::register();
        FailingStream::reset();
    }

    protected function tearDown(): void
    {
        FailingStream::reset();
        stream_wrapper_unregister(FailingStream::SCHEME);
    }

    public function test_the_staged_stream_carries_the_content_type_header_and_the_whole_body(): void
    {
        $staged = StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            'the body bytes',
            static fn ($stream): string => (string) stream_get_contents($stream),
        );

        self::assertSame("Content-Type: multipart/form-data; boundary=B\r\n\r\nthe body bytes", $staged);
    }

    /**
     * A stream that accepts a few bytes per call is the ordinary case, not
     * a failure: the loop has to keep writing until every byte is in.
     */
    public function test_a_stream_that_accepts_a_few_bytes_at_a_time_still_receives_the_whole_body(): void
    {
        FailingStream::$chunkSize = 7;
        $body = str_repeat('abcdefghij', 100);

        $staged = StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            $body,
            static fn ($stream): string => (string) stream_get_contents($stream),
            FailingStream::open(...),
        );

        self::assertSame("Content-Type: multipart/form-data; boundary=B\r\n\r\n{$body}", $staged);
    }

    public function test_a_stream_that_stops_accepting_bytes_fails_instead_of_parsing_a_prefix(): void
    {
        FailingStream::$chunkSize = 8;
        FailingStream::$refuseAfter = 32;
        $parsed = false;

        try {
            StagedMultipartBody::parse(
                'multipart/form-data; boundary=B',
                str_repeat('x', 1_000),
                static function () use (&$parsed): string {
                    $parsed = true;

                    return '';
                },
                FailingStream::open(...),
            );

            self::fail('a body that could not be staged whole must not be parsed');
        } catch (FormStagingException $e) {
            self::assertStringContainsString('wrote 32 of', $e->getMessage());
        }

        self::assertFalse($parsed, 'the parser must never see a partially staged body');
        self::assertTrue(FailingStream::$closed, 'the staging stream is closed even when the write failed');
    }

    public function test_a_stream_that_cannot_be_opened_is_an_infrastructure_failure(): void
    {
        $this->expectException(FormStagingException::class);

        StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            'body',
            static fn (): string => 'never',
            static fn (): bool => false,
        );
    }

    public function test_a_close_that_fails_is_reported_when_nothing_else_did(): void
    {
        FailingStream::$failOnClose = true;

        $this->expectException(FormStagingException::class);
        $this->expectExceptionMessage('Failed to close the php://temp stream a form body was staged in.');

        StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            'body',
            static fn (): string => 'parsed',
            FailingStream::open(...),
        );
    }

    /**
     * `null` is an answer a parser is entitled to give — a body with no
     * parts in it — and it has to arrive at the caller as itself, not as
     * a staging failure or a substituted default.
     */
    public function test_a_parser_that_answers_null_has_that_answer_returned(): void
    {
        $staged = StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            'body',
            static fn (): ?string => null,
        );

        self::assertNull($staged);
    }

    /**
     * The primary failure is what the caller needs: a close that also
     * failed says nothing about why the request could not be served, and
     * replacing the real failure with it would send an adapter down the
     * wrong path entirely.
     */
    public function test_a_parse_failure_survives_a_close_that_also_fails(): void
    {
        FailingStream::$failOnClose = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the parser said no');

        StagedMultipartBody::parse(
            'multipart/form-data; boundary=B',
            'body',
            static fn (): string => throw new RuntimeException('the parser said no'),
            FailingStream::open(...),
        );
    }
}
