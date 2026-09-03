<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Middleware\Exception\HttpStatusMappingException;
use Kinetis\Http\Middleware\ExceptionHandlerMiddleware;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Fixtures\ConfigurableHttpStatusException;
use Kinetis\Tests\Fixtures\FixtureHttpStatusException;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Fixtures\ThrowingLogger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ExceptionHandlerMiddlewareTest extends TestCase
{
    public function test_a_response_from_the_inner_handler_passes_through_untouched(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static fn () => new Response(200));

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_uncaught_throwable_from_the_inner_handler_becomes_a_500(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_uncaught_throwable_is_logged_with_the_request_method_and_path(): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $middleware->process(new ServerRequest('POST', '/users'), $handler);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('POST', $logger->records[0]['context']['method']);
        self::assertSame('/users', $logger->records[0]['context']['path']);
        self::assertSame('boom', $logger->records[0]['context']['message']);
        self::assertInstanceOf(RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    public function test_the_development_500_body_carries_the_exception_details(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Development);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        /** @var array{error: string, exception: string, message: string, location: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Internal server error.', $body['error']);
        self::assertSame(RuntimeException::class, $body['exception']);
        self::assertSame('boom', $body['message']);
        self::assertStringContainsString(basename(__FILE__), $body['location']);
    }

    public function test_the_production_500_body_stays_generic_even_when_passed_explicitly(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_http_status_exception_becomes_its_own_declared_status(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'bad input'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_http_status_exception_is_not_logged(): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertCount(0, $logger->records);
    }

    public function test_an_http_status_exception_is_not_gated_by_environment(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'bad input'], json_decode((string) $response->getBody(), true));
    }

    /**
     * A throwing logger is the whole point of the SafeLogger boundary:
     * the generic production 500 must still come back, and the logger's
     * own failure must never escape and replace it.
     */
    public function test_a_throwing_logger_does_not_prevent_the_production_500(): void
    {
        $logger = new ThrowingLogger();
        $middleware = new ExceptionHandlerMiddleware($logger, AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
        self::assertCount(1, $logger->entries, 'the logging attempt itself was made, and failed, before being discarded');
    }

    /**
     * The development counterpart: the detailed 500 body must still come
     * back even though logging it failed.
     */
    public function test_a_throwing_logger_does_not_prevent_the_development_500(): void
    {
        $logger = new ThrowingLogger();
        $middleware = new ExceptionHandlerMiddleware($logger, AppEnvironment::Development);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        /** @var array{exception: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(RuntimeException::class, $body['exception']);
        self::assertSame('boom', $body['message']);
    }

    /**
     * A generic exception's message that is not valid UTF-8 must still
     * produce a parseable development 500 body, not an uncaught
     * JsonException from inside internalErrorResponse() itself.
     */
    public function test_a_development_500_for_an_invalid_utf8_message_is_valid_json(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Development);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException("bad: \xC3\x28 end");
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // The exact, deterministic result of JSON_INVALID_UTF8_SUBSTITUTE
        // — see ErrorResponseTest's own identical assertion for why this
        // is the precise string, not an approximate one.
        self::assertSame("bad: \u{FFFD}( end", $decoded['message']);
    }

    /**
     * An HttpStatusExceptionInterface's own message is exactly as
     * arbitrary as a generic exception's — it must keep its declared
     * status and produce parseable JSON, not fall through to a 500 from
     * an uncaught JsonException.
     */
    public function test_an_http_status_exception_with_invalid_utf8_in_its_message_preserves_its_status(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException("bad: \xC3\x28 end");
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(400, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // The exact, deterministic result of JSON_INVALID_UTF8_SUBSTITUTE
        // — see ErrorResponseTest's own identical assertion for why this
        // is the precise string, not an approximate one.
        self::assertSame("bad: \u{FFFD}( end", $decoded['error']);
    }

    /**
     * httpStatus() itself throwing must not escape process() — the exact
     * defect this issue closes: a sibling catch clause never sees an
     * exception thrown from inside another catch's own body, so
     * converting HttpStatusExceptionInterface has to be inside the same
     * terminal catch as everything else, not a sibling of it.
     */
    public function test_an_http_status_exception_whose_httpstatus_itself_throws_still_becomes_a_500(): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () {
            throw new ConfigurableHttpStatusException('broken', new RuntimeException('httpStatus() failed'));
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));

        self::assertCount(1, $logger->records);
        // The original, broken exception — not a generic "something
        // went wrong" placeholder — is what gets logged.
        self::assertInstanceOf(ConfigurableHttpStatusException::class, $logger->records[0]['context']['exception']);
        self::assertSame('broken', $logger->records[0]['context']['message']);
        // Plus the secondary failure that made mapping impossible,
        // chaining back to the real cause via getPrevious().
        $mappingFailure = $logger->records[0]['context']['mappingFailure'];
        self::assertInstanceOf(HttpStatusMappingException::class, $mappingFailure);
        self::assertInstanceOf(RuntimeException::class, $mappingFailure->getPrevious());
        self::assertSame('httpStatus() failed', $mappingFailure->getPrevious()->getMessage());
    }

    /**
     * @return list<array{int}>
     */
    public static function invalidHttpStatuses(): array
    {
        return [
            'below 400' => [399],
            'far below 400' => [0],
            'above 599' => [600],
            'far above 599' => [10000],
            'a representative non-error status (200)' => [200],
            'a representative redirect status (302)' => [302],
        ];
    }

    #[DataProvider('invalidHttpStatuses')]
    public function test_a_status_outside_the_400_to_599_range_falls_back_to_a_500_instead_of_being_trusted(int $status): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () use ($status) {
            throw new ConfigurableHttpStatusException('broken', $status);
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));

        self::assertCount(1, $logger->records);
        self::assertInstanceOf(ConfigurableHttpStatusException::class, $logger->records[0]['context']['exception']);
        $mappingFailure = $logger->records[0]['context']['mappingFailure'];
        self::assertInstanceOf(HttpStatusMappingException::class, $mappingFailure);
        self::assertStringContainsString((string) $status, $mappingFailure->getMessage());
    }

    /**
     * @return list<array{int}>
     */
    public static function validBoundaryHttpStatuses(): array
    {
        return [
            'the lower boundary (400)' => [400],
            'the upper boundary (599)' => [599],
        ];
    }

    #[DataProvider('validBoundaryHttpStatuses')]
    public function test_a_valid_boundary_status_is_preserved_exactly(int $status): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () use ($status) {
            throw new ConfigurableHttpStatusException('a real declared error', $status);
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['error' => 'a real declared error'], json_decode((string) $response->getBody(), true));
        // A valid, well-formed HttpStatusExceptionInterface is not a
        // framework bug — it must never be logged as one.
        self::assertCount(0, $logger->records);
    }

    /**
     * The two failure modes together: a broken HttpStatusExceptionInterface
     * whose fallback logging attempt itself fails. The production 500
     * must still come back, exactly like any other uncaught exception
     * hitting a throwing logger.
     */
    public function test_a_malformed_http_status_exception_with_a_throwing_logger_still_becomes_a_production_500(): void
    {
        $logger = new ThrowingLogger();
        $middleware = new ExceptionHandlerMiddleware($logger, AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new ConfigurableHttpStatusException('broken', 999);
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
        self::assertCount(1, $logger->entries, 'the logging attempt itself was made, and failed, before being discarded');
        self::assertInstanceOf(HttpStatusMappingException::class, $logger->entries[0]['context']['mappingFailure']);
    }

    /**
     * The development counterpart: the detailed 500 body — describing
     * the original ConfigurableHttpStatusException, not the mapping
     * failure or the logger's own failure — must still come back.
     */
    public function test_a_malformed_http_status_exception_with_a_throwing_logger_still_becomes_a_development_500(): void
    {
        $logger = new ThrowingLogger();
        $middleware = new ExceptionHandlerMiddleware($logger, AppEnvironment::Development);
        $handler = new CallableRequestHandler(static function () {
            throw new ConfigurableHttpStatusException('broken', 999);
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        /** @var array{exception: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(ConfigurableHttpStatusException::class, $body['exception']);
        self::assertSame('broken', $body['message']);
        self::assertCount(1, $logger->entries, 'the logging attempt itself was made, and failed, before being discarded');
    }
}
