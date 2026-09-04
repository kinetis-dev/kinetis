<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Exception\UnresolvableParameterException;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\BuiltinCoverageController;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\ImpossiblePathArrayController;
use Kinetis\Tests\Http\Fixtures\ImpossiblePathNullController;
use Kinetis\Tests\Http\Fixtures\ImpossibleQueryNullController;
use Kinetis\Tests\Http\Fixtures\MultipleUnsupportedFieldsController;
use Kinetis\Tests\Http\Fixtures\NoteController;
use Kinetis\Tests\Http\Fixtures\NullableFieldsController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\OrderItemsController;
use Kinetis\Tests\Http\Fixtures\PlainArrayFieldController;
use Kinetis\Tests\Http\Fixtures\QueryLiteralController;
use Kinetis\Tests\Http\Fixtures\RawRequestController;
use Kinetis\Tests\Http\Fixtures\RequiredTagSearchController;
use Kinetis\Tests\Http\Fixtures\TagSearchController;
use Kinetis\Tests\Http\Fixtures\UnsupportedBodyFieldController;
use Kinetis\Tests\Http\Fixtures\UnsupportedCallableBodyFieldController;
use Kinetis\Tests\Http\Fixtures\UploadController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@example.com']));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@example.com'],
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

    // --- A syntactically invalid or non-object JSON body is a 400,
    // distinct from a 422 field-validation failure — never silently
    // treated as an empty/default body. ---

    public function test_malformed_json_body_returns_400(): void
    {
        $router = $this->router();
        $match = $router->match('POST', '/users');
        $request = new ServerRequest('POST', '/users', body: '{"name": "unterminated');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Request body is not valid JSON.'],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function nonObjectJsonBodies(): iterable
    {
        yield 'JSON null' => ['null'];
        yield 'a JSON string' => ['"just a string"'];
        yield 'a JSON number' => ['42'];
        yield 'a JSON boolean' => ['true'];
    }

    #[DataProvider('nonObjectJsonBodies')]
    public function test_a_syntactically_valid_but_non_object_json_body_returns_400(string $body): void
    {
        $router = $this->router();
        $match = $router->match('POST', '/users');
        $request = new ServerRequest('POST', '/users', body: $body);

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Request body must be a JSON object.'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_an_empty_body_is_treated_as_no_data_not_a_malformed_body_error(): void
    {
        $router = $this->router();
        $match = $router->match('PATCH', '/users/42/preferences');
        $request = new ServerRequest('PATCH', '/users/42/preferences', body: '');

        $response = $this->dispatcher()->dispatch($match, $request);

        // An all-optional #[Body] DTO with no data at all is exactly what
        // an empty body should produce — the fields fall back to their
        // own defaults, the same as a plain `{}` body already does.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['id' => 42, 'theme' => null, 'notificationsEnabled' => true],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_malformed_body_is_rejected_even_when_every_field_is_optional(): void
    {
        $router = $this->router();
        $match = $router->match('PATCH', '/users/42/preferences');
        $request = new ServerRequest('PATCH', '/users/42/preferences', body: 'null');

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(400, $response->getStatusCode());
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

    // KINETIS-75: proving real dispatch outcomes match exactly what
    // JsonSchema now declares — a defaultless nullable field rejects
    // omission but accepts an explicit null; a defaulted nullable field
    // (scalar, nested DTO, or #[ListOf] array) accepts both omission and
    // an explicit null.

    private function nullableFieldsRouter(): Router
    {
        $router = new Router();
        $router->register(NullableFieldsController::class);

        return $router;
    }

    public function test_a_defaultless_nullable_field_rejects_omission(): void
    {
        $match = $this->nullableFieldsRouter()->match('POST', '/nullable-fields');
        $request = new ServerRequest('POST', '/nullable-fields', body: json_encode([]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['is required.'], $body['errors']['requiredNullable']);
    }

    public function test_a_defaultless_nullable_field_accepts_an_explicit_null(): void
    {
        $match = $this->nullableFieldsRouter()->match('POST', '/nullable-fields');
        $request = new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => null]));

        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['requiredNullable' => null, 'optionalNullable' => null, 'optionalItem' => null, 'optionalItems' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_defaulted_nullable_scalar_accepts_both_omission_and_an_explicit_null(): void
    {
        $match = $this->nullableFieldsRouter()->match('POST', '/nullable-fields');

        $omitted = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x'])),
        );
        $explicitNull = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x', 'optionalNullable' => null])),
        );

        self::assertSame(200, $omitted->getStatusCode());
        self::assertSame(200, $explicitNull->getStatusCode());
        self::assertNull(json_decode((string) $omitted->getBody(), true)['optionalNullable']);
        self::assertNull(json_decode((string) $explicitNull->getBody(), true)['optionalNullable']);
    }

    public function test_a_nullable_nested_dto_accepts_both_omission_and_an_explicit_null(): void
    {
        $match = $this->nullableFieldsRouter()->match('POST', '/nullable-fields');

        $omitted = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x'])),
        );
        $explicitNull = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x', 'optionalItem' => null])),
        );
        $present = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode([
                'requiredNullable' => 'x',
                'optionalItem' => ['product' => 'widget', 'quantity' => 3],
            ])),
        );

        self::assertSame(200, $omitted->getStatusCode());
        self::assertSame(200, $explicitNull->getStatusCode());
        self::assertSame(200, $present->getStatusCode());
        self::assertNull(json_decode((string) $omitted->getBody(), true)['optionalItem']);
        self::assertNull(json_decode((string) $explicitNull->getBody(), true)['optionalItem']);
        self::assertSame(3, json_decode((string) $present->getBody(), true)['optionalItem']);
    }

    public function test_a_nullable_list_of_field_accepts_both_omission_and_an_explicit_null(): void
    {
        $match = $this->nullableFieldsRouter()->match('POST', '/nullable-fields');

        $omitted = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x'])),
        );
        $explicitNull = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode(['requiredNullable' => 'x', 'optionalItems' => null])),
        );
        $present = $this->dispatcher()->dispatch(
            $match,
            new ServerRequest('POST', '/nullable-fields', body: json_encode([
                'requiredNullable' => 'x',
                'optionalItems' => [['product' => 'a', 'quantity' => 1], ['product' => 'b', 'quantity' => 2]],
            ])),
        );

        self::assertSame(200, $omitted->getStatusCode());
        self::assertSame(200, $explicitNull->getStatusCode());
        self::assertSame(200, $present->getStatusCode());
        self::assertNull(json_decode((string) $omitted->getBody(), true)['optionalItems']);
        self::assertNull(json_decode((string) $explicitNull->getBody(), true)['optionalItems']);
        self::assertSame(2, json_decode((string) $present->getBody(), true)['optionalItems']);
    }

    // KINETIS-76 follow-up: runtime HTTP coverage for a wrong-shaped
    // plain-array #[Body] field and the complete builtin-type policy,
    // through a real dispatched request — not just a Hydrator unit call.

    public function test_a_wrong_shaped_plain_array_body_field_returns_422_not_a_type_error(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode(['tags' => 'not-an-array']));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be an array, value given.'], $body['errors']['tags']);
    }

    public function test_a_correctly_shaped_plain_array_body_field_dispatches_normally(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode(['tags' => ['a', 'b']]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tags' => ['a', 'b'], 'optionalTags' => null], json_decode((string) $response->getBody(), true));
    }

    // The requested end-to-end plain *nullable* array case — distinct
    // from #[ListOf]'s own nullable-array coverage in NullableFieldsController,
    // which additionally hydrates each element as a nested DTO.

    public function test_an_omitted_nullable_plain_array_body_field_uses_its_default(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode(['tags' => ['a']]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull(json_decode((string) $response->getBody(), true)['optionalTags']);
    }

    public function test_an_explicit_null_for_a_nullable_plain_array_body_field_dispatches_normally(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode(['tags' => ['a'], 'optionalTags' => null]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull(json_decode((string) $response->getBody(), true)['optionalTags']);
    }

    public function test_a_present_nullable_plain_array_body_field_dispatches_normally(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode([
            'tags' => ['a'],
            'optionalTags' => ['b', 'c'],
        ]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['b', 'c'], json_decode((string) $response->getBody(), true)['optionalTags']);
    }

    public function test_a_wrong_shaped_nullable_plain_array_body_field_returns_422(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: json_encode([
            'tags' => ['a'],
            'optionalTags' => ['key' => 'value'],
        ]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be a JSON array, not a JSON object.'], $body['errors']['optionalTags']);
    }

    /**
     * The real gap array_is_list() alone cannot close: a JSON object
     * whose own keys happen to look like a sequential list
     * ({"0":"a","1":"b"}) decodes to the identical PHP shape a real JSON
     * array does — array_is_list() is true for both. This can only be
     * constructed as a literal raw JSON string, deliberately: PHP's own
     * json_encode() would turn an equivalent PHP array back into a real
     * JSON array ([...]), which is exactly why this needs a real,
     * hand-written wire body rather than json_encode() of an
     * already-correct-shape PHP value, and proves the fix operates on
     * real decoded JSON bytes, not just already-flattened PHP arrays.
     */
    public function test_a_json_object_with_sequential_numeric_keys_is_still_rejected(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: '{"tags": {"0": "a", "1": "b"}}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be a JSON array, not a JSON object.'], $body['errors']['tags']);
    }

    public function test_an_empty_json_object_is_still_rejected_for_a_plain_array_field(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $match = $router->match('POST', '/plain-array-field');

        $request = new ServerRequest('POST', '/plain-array-field', body: '{"tags": {}}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be a JSON array, not a JSON object.'], $body['errors']['tags']);
    }

    /**
     * A #[ListOf] element is genuinely expected to be an object (a
     * nested DTO) — proves the JsonObject unwrap in hydrateListItem()
     * actually hydrates it, through a real request with real JSON bytes,
     * rather than passing the raw marker through unchanged.
     */
    public function test_a_real_json_object_list_element_still_hydrates_as_a_nested_dto(): void
    {
        $router = new Router();
        $router->register(OrderItemsController::class);
        $match = $router->match('POST', '/orders-with-items');

        $request = new ServerRequest('POST', '/orders-with-items', body: '{"customerName": "Alon", "items": [{"product": "widget", "quantity": 3}]}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['customerName' => 'Alon', 'items' => [['product' => 'widget', 'quantity' => 3]]],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * mixed must never leak a JsonObject wrapper to application code —
     * a real JSON object value for a mixed-typed field arrives as the
     * plain nested array it always did, through a real dispatched
     * request with real JSON bytes.
     */
    public function test_a_real_json_object_for_a_mixed_typed_field_is_unwrapped_to_a_plain_array(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = new ServerRequest('POST', '/builtin-coverage', body: '{"tags": [], "items": [], "note": {"nested": "value"}}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['nested' => 'value'], json_decode((string) $response->getBody(), true)['note']);
    }

    // KINETIS-76 third follow-up: a form-encoded #[Body] shares its DTO
    // class with the JSON-body path — the same field can arrive either
    // way depending on the client's own Content-Type — so `bool`/`true`/
    // `false`-typed fields get the identical "true"/"false" literal
    // normalization #[Query]/path already has, scoped to genuinely
    // form-encoded requests specifically so a JSON request for the same
    // DTO class still rejects a wrong-shaped value exactly as before.
    // See "Form-encoded and multipart bodies get the raw-string rules
    // too" in routing-validation.md. Verified for real here, not just
    // documented in prose.

    public function test_a_form_encoded_standalone_true_field_accepts_the_string_true_spelling(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = (new ServerRequest('POST', '/builtin-coverage'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['tags' => [], 'items' => [], 'confirmed' => 'true']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getBody(), true)['confirmed']);
    }

    /**
     * A spelling that is neither the normalized "true"/"false" form nor
     * the raw PHP literal still 422s — proving normalizeFormLiteral()
     * genuinely narrows the accepted spelling, rather than making the
     * check pass unconditionally once form encoding is involved at all.
     */
    public function test_a_form_encoded_standalone_true_field_still_rejects_a_spelling_that_is_neither_convention(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = (new ServerRequest('POST', '/builtin-coverage'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['tags' => [], 'items' => [], 'confirmed' => 'yes']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be true, value given.'], $body['errors']['confirmed']);
    }

    /**
     * The identical DTO class still works correctly for the exact same
     * field over a real JSON body, on the same route — proving the
     * form-encoded normalization above is genuinely scoped to a
     * form-encoded request specifically, not a change to standalone
     * true/false's own policy for a real JSON boolean.
     */
    public function test_the_same_route_still_accepts_a_real_json_true_for_the_identical_field(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = new ServerRequest('POST', '/builtin-coverage', body: '{"tags": [], "items": [], "confirmed": true}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getBody(), true)['confirmed']);
    }

    /**
     * A JSON body's own real "true"/"false" *strings* (not the literal
     * boolean) still correctly fail on the same field — the
     * normalization above never applies outside a genuinely
     * form-encoded request, so this is not weakened by it.
     */
    public function test_a_json_body_still_rejects_the_string_true_for_a_standalone_true_field(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = new ServerRequest('POST', '/builtin-coverage', body: '{"tags": [], "items": [], "confirmed": "true"}');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be true, value given.'], $body['errors']['confirmed']);
    }

    /**
     * The full builtin-category sweep, through a real dispatched request:
     * a correct payload succeeds across array/iterable/mixed/null/true/
     * false in one call, and a single wrong-shaped field among otherwise-
     * correct ones still reports only that field's own error.
     */
    public function test_a_correct_payload_across_every_supported_builtin_category_dispatches_normally(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = new ServerRequest('POST', '/builtin-coverage', body: json_encode([
            'tags' => ['a', 'b'],
            'items' => ['c'],
            'note' => 42,
            'marker' => null,
            'confirmed' => true,
            'declined' => false,
        ]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['tags' => ['a', 'b'], 'items' => ['c'], 'note' => 42, 'marker' => null, 'confirmed' => true, 'declined' => false],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_wrong_shaped_standalone_false_body_field_returns_422_alongside_a_correct_payload(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $match = $router->match('POST', '/builtin-coverage');

        $request = new ServerRequest('POST', '/builtin-coverage', body: json_encode([
            'tags' => [],
            'items' => [],
            'declined' => 'nope',
        ]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be false, value given.'], $body['errors']['declined']);
    }

    /**
     * The core guarantee item 2 of this issue closes: a route whose
     * #[Body] DTO carries a genuinely unsupported builtin type
     * (`object`) still registers and dispatches — Router::register()
     * never needs OpenAPI generation to have run — and a real request
     * carrying a value for that field gets a clean 422, never a raw
     * TypeError escaping the constructor. This holds regardless of
     * whether OpenApiGenerator::generate() (which does still refuse to
     * describe this same route, see OpenApiGeneratorTest) is ever called
     * for this application at all.
     */
    public function test_a_route_with_an_unsupported_body_field_still_dispatches_and_returns_422_not_a_type_error(): void
    {
        $router = new Router();
        $router->register(UnsupportedBodyFieldController::class);
        $match = $router->match('POST', '/unsupported-body-field');

        $request = new ServerRequest('POST', '/unsupported-body-field', body: json_encode(['extra' => ['a' => 1]]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(
            ['cannot be provided through JSON input — no request value can construct a plain object.'],
            $body['errors']['extra'],
        );
    }

    /**
     * `callable`'s own equivalent of the `object` test above — proving
     * the identical runtime guarantee for the second rejected category:
     * the route still registers and dispatches, and a genuinely valid
     * PHP callable string ("strtoupper") is still rejected rather than
     * silently accepted, matching HydratorTest's own unit-level proof of
     * this but through a real dispatched HTTP request.
     */
    public function test_a_route_with_an_unsupported_callable_body_field_still_dispatches_and_returns_422(): void
    {
        $router = new Router();
        $router->register(UnsupportedCallableBodyFieldController::class);
        $match = $router->match('POST', '/unsupported-callable-body-field');

        $request = new ServerRequest('POST', '/unsupported-callable-body-field', body: json_encode(['handler' => 'strtoupper']));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(
            ['cannot be provided through JSON input — callable values are not accepted.'],
            $body['errors']['handler'],
        );
    }

    /**
     * Failure atomicity: both rejected categories in one request must
     * both surface in the same 422, matching Hydrator's own "all fields
     * validated up front, not just the first" promise (see its class
     * docblock) — proven here for object/callable specifically, not just
     * for ordinary constraint violations.
     */
    public function test_both_unsupported_fields_report_together_in_one_response(): void
    {
        $router = new Router();
        $router->register(MultipleUnsupportedFieldsController::class);
        $match = $router->match('POST', '/multiple-unsupported-fields');

        $request = new ServerRequest('POST', '/multiple-unsupported-fields', body: json_encode([
            'extra' => ['a' => 1],
            'handler' => 'strtoupper',
        ]));
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('extra', $body['errors']);
        self::assertArrayHasKey('handler', $body['errors']);
    }

    // KINETIS-76 follow-up: a #[Query]/path value is a raw string, never
    // an already-decoded JSON value the way a #[Body] field's is — the
    // shared type-mismatch check is genuinely identical, but the *value*
    // reaching it depends on source-specific normalization first. Proven
    // here through real dispatched requests, not just the normalization
    // helper in isolation.

    public function test_a_query_boolean_accepts_the_openapi_documented_true_false_spelling(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['flag' => 'true']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getBody(), true)['flag']);
    }

    public function test_a_query_boolean_still_accepts_the_pre_existing_one_zero_spelling(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['flag' => '0']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getBody(), true)['flag']);
    }

    public function test_a_query_boolean_rejects_a_spelling_that_is_neither_convention(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['flag' => 'yes']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_a_query_standalone_true_typed_parameter_accepts_the_true_spelling(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['confirmed' => 'true']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getBody(), true)['confirmed']);
    }

    public function test_a_query_standalone_true_typed_parameter_rejects_the_false_spelling(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['confirmed' => 'false']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string, list<string>>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['must be true, boolean given.'], $body['errors']['confirmed']);
    }

    public function test_a_query_standalone_false_typed_parameter_accepts_the_false_spelling(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $request = (new ServerRequest('GET', '/query-literals'))->withQueryParams(['declined' => 'false']);
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getBody(), true)['declined']);
    }

    public function test_omitted_query_literals_use_their_defaults(): void
    {
        $router = new Router();
        $router->register(QueryLiteralController::class);
        $match = $router->match('GET', '/query-literals');

        $response = $this->dispatcher()->dispatch($match, new ServerRequest('GET', '/query-literals'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['flag' => false, 'confirmed' => true, 'declined' => false],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * The core guarantee: a required standalone-`null`-typed #[Query]
     * parameter can never be satisfied by any request, so it's rejected
     * at register() itself — the guaranteed boundary every route passes
     * through regardless of deployment shape (live discovery, or
     * Kinetis\Cache\Compiler's own AOT build, which discovers routes to
     * compile via this exact same call) — rather than deferred to this
     * route's first real dispatch, which would have let it register and
     * be advertised by OpenApiGenerator with no error at all.
     */
    public function test_a_required_standalone_null_typed_query_parameter_is_rejected_at_registration(): void
    {
        $router = new Router();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessage('marker');

        $router->register(ImpossibleQueryNullController::class);
    }

    /**
     * The path-sourced sibling: unconditionally rejected regardless of
     * any declared default, since a matched route's own placeholder
     * capture always supplies a real, non-empty string — there is no
     * "value missing, use the default" case a default could ever help
     * with here, unlike #[Query].
     */
    public function test_a_standalone_null_typed_path_parameter_is_rejected_at_registration(): void
    {
        $router = new Router();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessage('marker');

        $router->register(ImpossiblePathNullController::class);
    }

    /**
     * A route placeholder is always one string segment — an
     * array/iterable-typed path parameter can never be satisfied by any
     * request, unlike a #[Query] array. Rejected the same way, at
     * registration.
     */
    public function test_an_array_typed_path_parameter_is_rejected_at_registration(): void
    {
        $router = new Router();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessage('tags');

        $router->register(ImpossiblePathArrayController::class);
    }

    // An array/iterable-typed #[Query] parameter is bound from the
    // repeated-key wire form, `?tags=a&tags=b` — the OpenAPI spec's own
    // default array serialization, and the one form that satisfies it.
    // PSR-7's getQueryParams() (built from PHP's native parse_str())
    // cannot represent that form at all, so every check below dispatches
    // a real request built from a raw URI query string rather than
    // seeding withQueryParams() with an already-shaped PHP array.

    public function test_a_query_array_parameter_accepts_the_openapi_standard_repeated_key_form(): void
    {
        $router = new Router();
        $router->register(TagSearchController::class);
        $match = $router->match('GET', '/tag-search');

        $request = new ServerRequest('GET', '/tag-search?tags=a&tags=b');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tags' => ['a', 'b']], json_decode((string) $response->getBody(), true));
    }

    public function test_a_single_repeated_key_query_array_value_still_becomes_a_one_element_list(): void
    {
        $router = new Router();
        $router->register(TagSearchController::class);
        $match = $router->match('GET', '/tag-search');

        $request = new ServerRequest('GET', '/tag-search?tags=solo');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tags' => ['solo']], json_decode((string) $response->getBody(), true));
    }

    /**
     * PHP's bracket spelling sends a different key — the name on the
     * wire is `tags[]`, not `tags` — so it satisfies no #[Query('tags')]
     * parameter: the value is missing, and the parameter's own default
     * is what the controller sees, exactly as for a request that never
     * mentioned the key at all.
     */
    public function test_a_bracketed_query_key_does_not_satisfy_an_array_query_parameter(): void
    {
        $router = new Router();
        $router->register(TagSearchController::class);
        $match = $router->match('GET', '/tag-search');

        $request = new ServerRequest('GET', '/tag-search?tags%5B%5D=a&tags%5B%5D=b');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tags' => []], json_decode((string) $response->getBody(), true));
    }

    /**
     * The same rejection with nothing to fall back on: a defaultless
     * array #[Query] parameter given only the bracket spelling fails
     * validation as an absent required value, rather than binding the
     * PHP array parse_str() would have built from it.
     */
    public function test_a_bracketed_query_key_leaves_a_required_array_query_parameter_missing(): void
    {
        $router = new Router();
        $router->register(RequiredTagSearchController::class);
        $match = $router->match('GET', '/required-tag-search');

        $request = new ServerRequest('GET', '/required-tag-search?tags%5B%5D=a');
        $response = $this->dispatcher()->dispatch($match, $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('tags', (string) $response->getBody());
    }

    public function test_an_omitted_query_array_parameter_uses_its_default(): void
    {
        $router = new Router();
        $router->register(TagSearchController::class);
        $match = $router->match('GET', '/tag-search');

        $response = $this->dispatcher()->dispatch($match, new ServerRequest('GET', '/tag-search'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tags' => []], json_decode((string) $response->getBody(), true));
    }
}
