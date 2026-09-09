<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\ValidationExceptionRendererInterface;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Http\Fixtures\RedirectingValidationRenderer;
use Kinetis\Tests\Http\Fixtures\RerenderingValidationRenderer;
use Kinetis\Tests\Http\Fixtures\ThrowingValidationRenderer;
use Kinetis\Tests\Http\Fixtures\ValidationRenderingController;
use Kinetis\Validation\Exception\ValidationException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The whole path a validation failure takes through a real Kernel:
 * raised while Dispatcher binds a #[Body] DTO, across the middleware
 * pipeline, into ExceptionHandlerMiddleware, and out through whichever
 * ValidationExceptionRendererInterface the application bound — or the
 * RFC 9457 default when it bound none.
 */
final class KernelValidationRenderingTest extends TestCase
{
    private const array JSON_HEADERS = ['Content-Type' => 'application/json'];

    /**
     * @param callable(AppScope): void|null $register
     */
    private function kernel(?callable $register = null): Kernel
    {
        $app = new AppScope();

        // Before boot(): the point of the constructor-default seam is
        // that an application binding made here wins over it through
        // ordinary autowiring, with nothing registered by default.
        if ($register !== null) {
            $register($app);
        }

        $app->boot();

        $router = new Router();
        $router->register(ValidationRenderingController::class);

        return new Kernel($app, $router);
    }

    private function invalidRequest(string $path = '/validation-rendering'): ServerRequest
    {
        return new ServerRequest('POST', $path, self::JSON_HEADERS, body: json_encode([
            'name' => 'Al',
            'email' => 'not-an-email',
        ]));
    }

    public function test_the_default_renderer_answers_with_an_rfc_9457_problem_document(): void
    {
        $response = $this->kernel()->handle($this->invalidRequest());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'The request data failed validation.',
            'errors' => [
                [
                    'path' => ['name'],
                    'code' => 'min_length',
                    'message' => 'must be at least 3 characters.',
                    'parameters' => ['length' => 3],
                ],
                [
                    'path' => ['email'],
                    'code' => 'email',
                    'message' => 'must be a valid email address.',
                    'parameters' => [],
                ],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * The document is only reached through the terminal middleware, so
     * a controller that validates nothing still answers normally — the
     * renderer is not in the path of a successful request.
     */
    public function test_a_valid_request_is_untouched_by_the_renderer_seam(): void
    {
        $request = new ServerRequest('POST', '/validation-rendering', self::JSON_HEADERS, body: json_encode([
            'name' => 'Alon',
            'email' => 'alon@example.com',
        ]));

        $response = $this->kernel()->handle($request);

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_an_application_renderer_bound_before_boot_replaces_the_default(): void
    {
        $kernel = $this->kernel(static function (AppScope $app): void {
            $app->bind(ValidationExceptionRendererInterface::class, RedirectingValidationRenderer::class);
        });

        $response = $kernel->handle($this->invalidRequest());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/validation-rendering', $response->getHeaderLine('Location'));
        self::assertSame('name,email', $response->getHeaderLine('X-Failed-Fields'));
    }

    /**
     * A deliberately re-rendered page keeps its 200: the boundary
     * validates no status range, so the whole `ResponseInterface`
     * domain stays application policy.
     */
    public function test_an_application_renderer_can_answer_with_an_unchanged_200(): void
    {
        $kernel = $this->kernel(static function (AppScope $app): void {
            $app->bind(ValidationExceptionRendererInterface::class, RerenderingValidationRenderer::class);
        });

        $response = $kernel->handle($this->invalidRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            '<ul><li>name: min_length</li><li>email: email</li></ul>',
            (string) $response->getBody(),
        );
    }

    public function test_a_throwing_renderer_cannot_escape_the_terminal_boundary(): void
    {
        $logger = new InMemoryLogger();
        $kernel = $this->kernel(static function (AppScope $app) use ($logger): void {
            $app->bind(ValidationExceptionRendererInterface::class, ThrowingValidationRenderer::class);
            $app->instance(LoggerInterface::class, $logger);
        });

        $response = $kernel->handle($this->invalidRequest());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));

        self::assertCount(1, $logger->records);
        $context = $logger->records[0]['context'];
        // The failure being rendered, and the failure to render it:
        // neither is useful without the other.
        self::assertInstanceOf(ValidationException::class, $context['exception']);
        self::assertSame(ThrowingValidationRenderer::MESSAGE, $context['renderFailure']->getMessage());
    }

    /**
     * Development's diagnostic 500 reports the validation failure
     * itself, since that is the exception the pipeline was handling —
     * the rendering failure travels in the log context beside it.
     */
    public function test_the_development_500_names_the_validation_failure_a_broken_renderer_could_not_render(): void
    {
        $kernel = $this->kernel(static function (AppScope $app): void {
            $app->bind(ValidationExceptionRendererInterface::class, ThrowingValidationRenderer::class);
            $app->instance(AppEnvironment::class, AppEnvironment::Development);
            $app->instance(LoggerInterface::class, new InMemoryLogger());
        });

        $response = $kernel->handle($this->invalidRequest());

        /** @var array{exception: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(ValidationException::class, $body['exception']);
        self::assertSame('Validation failed.', $body['message']);
    }

    /**
     * Route middleware sits inside the terminal boundary, so it gets
     * the failure first — the workflow that needs a live request scope
     * (flashing errors to a session before redirecting) without
     * replacing the global renderer for every other route.
     */
    public function test_route_middleware_catches_the_failure_before_the_terminal_renderer(): void
    {
        $kernel = $this->kernel(static function (AppScope $app): void {
            $app->bind(ValidationExceptionRendererInterface::class, ThrowingValidationRenderer::class);
        });

        $response = $kernel->handle($this->invalidRequest('/validation-rendering/caught'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/form', $response->getHeaderLine('Location'));
        self::assertSame('name,email', $response->getHeaderLine('X-Flashed-Fields'));
    }

    /**
     * The renderer is app-scoped and shared across requests, so the
     * default must hold no request or exception between them: two
     * failing requests through one Kernel produce two documents that
     * describe only their own request.
     */
    public function test_the_default_renderer_carries_nothing_between_requests(): void
    {
        $kernel = $this->kernel();

        $first = $kernel->handle($this->invalidRequest());
        $second = $kernel->handle(new ServerRequest('POST', '/validation-rendering', self::JSON_HEADERS, body: json_encode([
            'name' => 'Alon',
        ])));

        self::assertSame([['name'], ['email']], self::errorPaths($first));
        self::assertSame([['email']], self::errorPaths($second));
    }

    /**
     * @return list<mixed>
     */
    private static function errorPaths(ResponseInterface $response): array
    {
        /** @var array{errors: list<array{path: mixed}>} $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return array_column($body['errors'], 'path');
    }
}
