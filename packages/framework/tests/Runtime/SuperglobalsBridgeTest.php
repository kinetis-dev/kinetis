<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Http\StreamedResponse;
use Kinetis\Runtime\SuperglobalsBridge;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SuperglobalsBridgeTest extends TestCase
{
    public function test_emit_echoes_a_plain_responses_body(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], 'hello');

        ob_start();
        SuperglobalsBridge::emit($response);
        $output = ob_get_clean();

        self::assertSame('hello', $output);
    }

    public function test_emit_calls_a_streamed_responses_emitter_instead_of_reading_its_body(): void
    {
        $called = false;
        $inner = new Response(200, ['Content-Type' => 'text/event-stream']);
        $response = new StreamedResponse($inner, static function () use (&$called): void {
            $called = true;
            echo 'streamed';
        });

        ob_start();
        SuperglobalsBridge::emit($response);
        $output = ob_get_clean();

        self::assertTrue($called);
        self::assertSame('streamed', $output);
    }

    /**
     * Containment is not "don't stream": the emitter runs, writes what
     * it manages to write, and only its failure stops here. What the
     * client keeps is the truncated body — the only thing a response
     * whose status and headers are already sent can end as.
     */
    public function test_emit_contains_a_failing_emitter_and_reports_what_failed(): void
    {
        $invoked = false;
        $response = new StreamedResponse(
            new Response(200, ['Content-Type' => 'text/event-stream']),
            static function () use (&$invoked): void {
                $invoked = true;
                echo 'first-chunk';

                throw new RuntimeException('the upstream feed died mid-stream');
            },
        );

        ob_start();
        $entries = self::capturingTheLog(static fn () => SuperglobalsBridge::emit($response));
        $output = ob_get_clean();

        self::assertTrue($invoked, 'the emitter is invoked, not skipped');
        self::assertSame('first-chunk', $output);
        self::assertCount(1, $entries);
        self::assertStringContainsString(RuntimeException::class, $entries[0]);
        self::assertStringContainsString('the upstream feed died mid-stream', $entries[0]);
        self::assertStringContainsString(basename(__FILE__), $entries[0], 'the diagnostic names where the emitter failed');
    }

    /**
     * The one iteration of `FrankenPhpAdapter::run()`'s loop that this
     * suite can reach — the extension itself is not loadable here, which
     * {@see \Kinetis\Tests\Runtime\Adapters\FrankenPhpAdapterTest}
     * asserts — driven twice. A throwable escaping emit() escapes
     * `frankenphp_handle_request()` too and ends the worker thread with
     * it, so the request after a broken stream is the one that proves
     * containment did its job: it is served, from the same warm process,
     * in full.
     */
    public function test_a_failed_emitter_leaves_the_next_request_on_a_persistent_worker_serviceable(): void
    {
        $responses = [
            new StreamedResponse(
                new Response(200, ['Content-Type' => 'text/event-stream']),
                static function (): void {
                    echo 'partial';

                    throw new RuntimeException('the upstream feed died mid-stream');
                },
            ),
            new Response(200, ['Content-Type' => 'text/plain'], 'the next request'),
        ];

        $emitted = [];

        self::capturingTheLog(static function () use ($responses, &$emitted): void {
            foreach ($responses as $response) {
                ob_start();
                SuperglobalsBridge::emit($response);
                $emitted[] = ob_get_clean();
            }
        });

        self::assertSame(['partial', 'the next request'], $emitted);
    }

    /**
     * Only the emitter call is contained. A body this process cannot
     * read is this worker's failure like any other, and still propagates
     * — the reason the `try` wraps one call rather than the method.
     */
    public function test_emit_still_propagates_a_plain_body_it_cannot_read(): void
    {
        $stream = Stream::create('hello');
        $response = new Response(200, ['Content-Type' => 'text/plain'], $stream);
        $stream->detach();

        $this->expectException(RuntimeException::class);

        ob_start();

        try {
            SuperglobalsBridge::emit($response);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Runs $emit with `error_log()` pointed at a file of this test's own,
     * and returns what was written to it — one entry per line, PHP's own
     * leading timestamp stripped, since what this class controls is the
     * message.
     *
     * @param callable(): void $emit
     *
     * @return list<string>
     */
    private static function capturingTheLog(callable $emit): array
    {
        $log = tempnam(sys_get_temp_dir(), 'kinetis-emit-');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $emit();
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($log);
        }

        return array_map(
            static fn (string $entry): string => (string) preg_replace('/^\[[^\]]*\] /', '', $entry),
            array_values(array_filter(explode(PHP_EOL, $written), static fn (string $entry): bool => $entry !== '')),
        );
    }
}
