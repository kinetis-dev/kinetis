<?php

declare(strict_types=1);

namespace Kinetis\Tests\Logging;

use Kinetis\Logging\ErrorLogLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The logger bound by default in development, so an exception leaves a
 * trail with no logging setup at all. Output is captured by pointing
 * error_log() at a file for the duration of each test — asserting on
 * what was actually written, rather than that the call did not throw.
 */
final class ErrorLogLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'errorlog');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    /**
     * Runs $log with error_log() pointed at this test's own file, and
     * returns what it wrote.
     *
     * The redirect has to happen inside the test body rather than in
     * setUp(): PHPUnit applies its own error_log setting between setUp()
     * and the test method, to capture output per test, which discards
     * anything setUp() set.
     */
    private function logged(callable $log): string
    {
        $previousDestination = ini_set('error_log', $this->logFile);
        $previousType = ini_set('log_errors', '1');

        try {
            $log();
        } finally {
            if ($previousDestination !== false) {
                ini_set('error_log', $previousDestination);
            }

            if ($previousType !== false) {
                ini_set('log_errors', $previousType);
            }
        }

        return (string) file_get_contents($this->logFile);
    }

    public function test_writes_the_level_and_message(): void
    {
        $logged = $this->logged(static fn () => new ErrorLogLogger()->log(LogLevel::WARNING, 'disk is filling up'));

        self::assertStringContainsString('[warning] disk is filling up', $logged);
    }

    public function test_interpolates_placeholders_from_context(): void
    {
        $logged = $this->logged(static fn () => new ErrorLogLogger()->log(LogLevel::INFO, 'user {id} signed in from {ip}', [
            'id' => 42,
            'ip' => '203.0.113.9',
        ]));

        self::assertStringContainsString('user 42 signed in from 203.0.113.9', $logged);
    }

    /**
     * A non-scalar context value has no string form to substitute, so the
     * placeholder is left as written rather than rendered as "Array".
     */
    public function test_leaves_a_placeholder_alone_when_its_value_is_not_stringable(): void
    {
        $logged = $this->logged(static fn () => new ErrorLogLogger()->log(LogLevel::INFO, 'payload {data}', ['data' => ['a' => 1]]));

        self::assertStringContainsString('payload {data}', $logged);
    }

    public function test_appends_the_class_and_location_of_an_exception(): void
    {
        $exception = new RuntimeException('boom');

        $logged = $this->logged(static fn () => new ErrorLogLogger()->log(LogLevel::ERROR, 'request failed', ['exception' => $exception]));
        self::assertStringContainsString('request failed', $logged);
        self::assertStringContainsString(RuntimeException::class, $logged);
        self::assertStringContainsString(basename($exception->getFile()) . ':' . $exception->getLine(), $logged);
    }

    /**
     * `exception` is the PSR-3 convention for a Throwable; anything else
     * under that key is context like any other and must not be treated as
     * one.
     */
    public function test_a_non_throwable_under_the_exception_key_is_not_treated_as_one(): void
    {
        $logged = $this->logged(static fn () => new ErrorLogLogger()->log(LogLevel::ERROR, 'odd', ['exception' => 'not really']));

        // Asserted positively first: a "does not contain" check passes
        // trivially against an empty string, so it proves nothing unless
        // something was captured.
        self::assertStringContainsString('odd', $logged);
        self::assertStringNotContainsString(' at ', $logged);
    }
}
