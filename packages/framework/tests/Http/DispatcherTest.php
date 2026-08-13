<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\NoteController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\RawRequestController;
use Kinetis\Tests\Http\Fixtures\UploadController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    private function dispatcher(): Dispatcher
    {
        $app = new AppScope();
        $app->boot();

        return new Dispatcher($app);
    }

    private function router(): Router
    {
        $router = new Router();
        $router->register(UserController::class);

        return $router;
    }

    public function test_dispatches_a_body_bound_dto_and_returns_json(): void
    {
        $router = $this->router();
        $match = $router->match('POST', '/users');
        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc']));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@noy.cc'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_dispatches_query_bound_scalars_with_defaults(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users');
        $request = (new ServerRequest('GET', '/users'))->withQueryParams(['limit' => '5']);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['page' => 1, 'limit' => 5], json_decode((string) $response->getBody(), true));
    }

    public function test_dispatches_a_path_bound_scalar(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users/42');
        $request = new ServerRequest('GET', '/users/42');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_a_server_request_interface_typed_parameter_receives_the_raw_request(): void
    {
        $router = new Router();
        $router->register(RawRequestController::class);
        $match = $router->match('GET', '/raw-request');
        $request = new ServerRequest('GET', '/raw-request');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(
            ['path' => '/raw-request', 'method' => 'GET'],
            json_decode((string) $response->getBody(), true),
        );
    }

    private function uploadRouter(): Router
    {
        $router = new Router();
        $router->register(UploadController::class);

        return $router;
    }

    public function test_a_multipart_body_is_read_from_parsed_body_not_json_decoded(): void
    {
        $router = $this->uploadRouter();
        $match = $router->match('POST', '/avatars');

        $avatar = new UploadedFile(Stream::create('fake image bytes'), 17, UPLOAD_ERR_OK, 'avatar.png', 'image/png');
        $request = (new ServerRequest('POST', '/avatars'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary')
            ->withParsedBody(['name' => 'Alon'])
            ->withUploadedFiles(['avatar' => $avatar]);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(
            ['name' => 'Alon', 'filename' => 'avatar.png', 'contents' => 'fake image bytes'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_url_encoded_body_is_also_read_from_parsed_body(): void
    {
        $router = $this->uploadRouter();
        $match = $router->match('POST', '/avatars');

        $avatar = new UploadedFile(Stream::create('x'), 1, UPLOAD_ERR_OK, 'a.png', 'image/png');
        $request = (new ServerRequest('POST', '/avatars'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['name' => 'Url Encoded'])
            ->withUploadedFiles(['avatar' => $avatar]);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame('Url Encoded', json_decode((string) $response->getBody(), true)['name']);
    }

    public function test_an_uploaded_file_typed_parameter_receives_it_directly_without_body(): void
    {
        $router = $this->uploadRouter();
        $match = $router->match('POST', '/files');

        $file = new UploadedFile(Stream::create('contents'), 8, UPLOAD_ERR_OK, 'report.pdf', 'application/pdf');
        $request = (new ServerRequest('POST', '/files'))->withUploadedFiles(['file' => $file]);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(['filename' => 'report.pdf'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_body_bound_dto_with_a_nested_dto_field_hydrates_end_to_end(): void
    {
        $router = new Router();
        $router->register(OrderController::class);
        $match = $router->match('POST', '/orders');

        $request = new ServerRequest('POST', '/orders', body: json_encode([
            'customerName' => 'Alon',
            'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino'],
        ]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['id' => 1, 'customerName' => 'Alon', 'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino']],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_nested_dtos_invalid_field_returns_422_with_a_dotted_error_key(): void
    {
        $router = new Router();
        $router->register(OrderController::class);
        $match = $router->match('POST', '/orders');

        $request = new ServerRequest('POST', '/orders', body: json_encode([
            'customerName' => 'Alon',
            'shippingAddress' => ['street' => 'x', 'city' => 'Cupertino'],
        ]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('shippingAddress.street', $body['errors']);
    }

    public function test_a_controller_returning_a_response_interface_is_passed_through_untouched(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users/999/maybe');
        $request = new ServerRequest('GET', '/users/999/maybe');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['error' => 'User 999 not found.'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_controller_returning_a_response_interface_does_not_use_the_route_status_when_returning_plain_data(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users/42/maybe');
        $request = new ServerRequest('GET', '/users/42/maybe');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_invalid_body_returns_422_with_errors_instead_of_running_the_controller(): void
    {
        $router = $this->router();
        $match = $router->match('POST', '/users');
        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Al', 'email' => 'not-an-email']));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('name', $body['errors']);
        self::assertArrayHasKey('email', $body['errors']);
    }

    // --- A #[Query]/path value with the wrong shape is a 422, not a
    // silently-wrong cast ("not-a-number" -> 0, an array -> 1, a
    // non-numeric path segment -> 0). ---

    public function test_a_non_numeric_query_value_returns_422_instead_of_casting_to_zero(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users');
        $request = (new ServerRequest('GET', '/users'))->withQueryParams(['page' => 'not-a-number']);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('page', $body['errors']);
    }

    public function test_an_array_style_query_value_returns_422_instead_of_casting_to_one(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users');
        $request = (new ServerRequest('GET', '/users'))->withQueryParams(['page' => ['1', '2']]);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_a_non_numeric_path_segment_returns_422_instead_of_casting_to_zero(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users/abc');
        $request = new ServerRequest('GET', '/users/abc');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('id', $body['errors']);
    }

    public function test_multiple_query_type_mismatches_on_one_route_are_all_reported_together(): void
    {
        $router = $this->router();
        $match = $router->match('GET', '/users');
        $request = (new ServerRequest('GET', '/users'))->withQueryParams(['page' => 'x', 'limit' => 'y']);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('page', $body['errors']);
        self::assertArrayHasKey('limit', $body['errors']);
    }

    // --- #[Query]/path-parameter Constraint attributes are enforced. ---

    private function constrainedRouter(): Router
    {
        $router = new Router();
        $router->register(ConstrainedParametersController::class);

        return $router;
    }

    public function test_a_query_constraint_violation_returns_422(): void
    {
        $router = $this->constrainedRouter();
        $match = $router->match('GET', '/probe');
        $request = (new ServerRequest('GET', '/probe'))->withQueryParams(['page' => '-999', 'sort' => 'DROP']);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('page', $body['errors']);
        self::assertArrayHasKey('sort', $body['errors']);
    }

    public function test_a_query_value_satisfying_its_constraint_is_accepted(): void
    {
        $router = $this->constrainedRouter();
        $match = $router->match('GET', '/probe');
        $request = (new ServerRequest('GET', '/probe'))->withQueryParams(['page' => '5', 'sort' => 'desc']);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['page' => 5, 'sort' => 'desc'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_path_parameter_constraint_violation_returns_422(): void
    {
        $router = $this->constrainedRouter();
        $match = $router->match('GET', '/items/abc');
        $request = new ServerRequest('GET', '/items/abc');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('code', $body['errors']);
    }

    public function test_a_path_parameter_satisfying_its_constraint_is_accepted(): void
    {
        $router = $this->constrainedRouter();
        $match = $router->match('GET', '/items/ABC');
        $request = new ServerRequest('GET', '/items/ABC');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 'ABC'], json_decode((string) $response->getBody(), true));
    }

    public function test_derive_plan_tags_body_query_path_and_default_sources_correctly(): void
    {
        $router = $this->router();
        $indexRoute = $router->match('GET', '/users')->route;
        $showRoute = $router->match('GET', '/users/42')->route;

        $indexPlan = Dispatcher::derivePlan(new \ReflectionMethod(UserController::class, 'index'), $indexRoute);
        self::assertSame('page', $indexPlan[0]['name']);
        self::assertSame('query', $indexPlan[0]['source']);
        self::assertTrue($indexPlan[0]['hasDefault']);
        self::assertSame(1, $indexPlan[0]['defaultValue']);

        $showPlan = Dispatcher::derivePlan(new \ReflectionMethod(UserController::class, 'show'), $showRoute);
        self::assertSame('id', $showPlan[0]['name']);
        self::assertSame('path', $showPlan[0]['source']);

        $storeRoute = $router->match('POST', '/users')->route;
        $storePlan = Dispatcher::derivePlan(new \ReflectionMethod(UserController::class, 'store'), $storeRoute);
        self::assertSame('data', $storePlan[0]['name']);
        self::assertSame('body', $storePlan[0]['source']);
        self::assertSame('Kinetis\Tests\Http\Fixtures\CreateUserRequest', $storePlan[0]['dtoClass']);

        $rawRouter = new Router();
        $rawRouter->register(RawRequestController::class);
        $rawRoute = $rawRouter->match('GET', '/raw-request')->route;
        $rawPlan = Dispatcher::derivePlan(new \ReflectionMethod(RawRequestController::class, 'show'), $rawRoute);
        self::assertSame('request', $rawPlan[0]['source']);
    }

    public function test_a_hand_built_plan_resolves_a_request_identically_to_the_live_path(): void
    {
        $app = new AppScope();
        $app->boot();

        $plan = [
            'Kinetis\Tests\Http\Fixtures\UserController::show' => [
                ['name' => 'id', 'source' => 'path', 'dtoClass' => null, 'scalarType' => 'int', 'hasDefault' => false, 'defaultValue' => null, 'allowsNull' => false, 'constraints' => []],
            ],
        ];

        $dispatcher = new Dispatcher($app, $plan);
        $match = $this->router()->match('GET', '/users/42');
        $response = $dispatcher->dispatch($match, new ServerRequest('GET', '/users/42'));

        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_a_route_absent_from_the_plan_map_falls_back_to_live_reflection(): void
    {
        $app = new AppScope();
        $app->boot();

        // Binding plans keyed for a completely different route — this one
        // must still dispatch correctly via live derivePlan().
        $dispatcher = new Dispatcher($app, ['SomeOther\Class::method' => []]);
        $match = $this->router()->match('GET', '/users/42');
        $response = $dispatcher->dispatch($match, new ServerRequest('GET', '/users/42'));

        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_an_explicitly_null_body_field_for_a_non_nullable_parameter_returns_422(): void
    {
        $router = new Router();
        $router->register(NoteController::class);
        $match = $router->match('POST', '/notes');
        $request = new ServerRequest('POST', '/notes', body: json_encode(['title' => null, 'subtitle' => null]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must not be null.'], $body['errors']['title']);
    }

    public function test_an_explicitly_null_body_field_for_a_nullable_parameter_dispatches_normally(): void
    {
        $router = new Router();
        $router->register(NoteController::class);
        $match = $router->match('POST', '/notes');
        $request = new ServerRequest('POST', '/notes', body: json_encode(['title' => 'hello', 'subtitle' => null]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['title' => 'hello', 'subtitle' => null], json_decode((string) $response->getBody(), true));
    }

    public function test_a_missing_required_query_parameter_returns_422(): void
    {
        $router = new Router();
        $router->register(NoteController::class);
        $match = $router->match('GET', '/notes/search');

        $response = $this->dispatcher()->dispatch($match, new ServerRequest('GET', '/notes/search'));

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['is required.'], $body['errors']['term']);
    }

    public function test_a_missing_nullable_query_parameter_resolves_to_null(): void
    {
        $router = new Router();
        $router->register(NoteController::class);
        $match = $router->match('GET', '/notes/filter');

        $response = $this->dispatcher()->dispatch($match, new ServerRequest('GET', '/notes/filter'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['term' => null], json_decode((string) $response->getBody(), true));
    }

    public function test_a_missing_uploaded_file_for_a_non_nullable_parameter_returns_422(): void
    {
        $router = new Router();
        $router->register(UploadController::class);
        $match = $router->match('POST', '/files');

        $response = $this->dispatcher()->dispatch($match, new ServerRequest('POST', '/files'));

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['is required.'], $body['errors']['file']);
    }
}
