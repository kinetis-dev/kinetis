<?php

declare(strict_types=1);

namespace Kinetis\OpenApi;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\PaginatedItem;
use Kinetis\Http\Attributes\Query;
use Kinetis\Http\Attributes\Response;
use Kinetis\Http\Pagination\CursorPaginator;
use Kinetis\Http\Pagination\Paginator;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\Router;
use Kinetis\Validation\JsonSchema;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Builds an OpenAPI 3.1 document from a Router's registered routes — no
 * docblocks or separate annotations required. It reflects each route's
 * controller method the same way Dispatcher does at request time: a
 * #[Body] parameter's DTO becomes a requestBody schema (built by
 * Kinetis\Validation\JsonSchema from the same constraint attributes
 * Hydrator validates against), #[Query] and path parameters become
 * `parameters` entries, and the method's declared return type becomes the
 * default response's schema (see describeDefaultResponse()). This is why
 * it's "zero-config" — the metadata already exists for routing/validation,
 * this just reads it a second time for a different purpose.
 *
 * Every DTO schema referenced by a requestBody or response — including a
 * nested DTO reached through another one, at any depth — is deduplicated
 * into `components/schemas` and referenced by `$ref`, via schemaRefFor()
 * below, rather than inlined at every point of use. One instance of this
 * class is one `generate()` call's worth of state: the registry resets at
 * the start of generate() so reusing an instance across calls can't leak
 * a stale component from a previous document into a new one.
 *
 * A route's RouteAttribute status is only its *default* response — a
 * controller can return a ResponseInterface directly to produce a
 * different one at runtime (a 404 when a fetched entity doesn't exist, a
 * 3xx redirect, ...), which the route attribute alone can't describe.
 * Repeatable #[Response(status, description)] attributes on the same
 * method document those additional statuses; see describeOperation(). They
 * stay purely descriptive text with no derived schema, unlike the default
 * response — a #[Response(404, ...)] is typically describing a shape the
 * method's declared return type doesn't capture at all (an error body, not
 * the success DTO), so there's nothing correct to derive it from.
 *
 * #[Hidden] on a route method, or on the controller it is registered on,
 * excludes the route entirely — checked before describeOperation() ever
 * runs, so a hidden route's own DTOs never register a components/schemas
 * entry either. Read from the registered class rather than the declaring
 * one, so it obeys the same rule as every other attribute; see
 * Kinetis\Reflection\AttributeScope.
 *
 * A route returning Paginator/CursorPaginator describes `data` as a bare
 * {type: object} by default — the same class is reused by every paginated
 * route regardless of what it actually holds, so reflecting the return
 * type alone can't recover the item shape. #[PaginatedItem(SomeClass::class)]
 * on the method names it explicitly; see paginatedResponseSchema().
 */
final class OpenApiGenerator
{
    /** @var array<class-string, string> */
    private array $schemaNamesByClass = [];

    /** @var array<string, array<string, mixed>> */
    private array $componentSchemas = [];

    public function __construct(
        private readonly Router $router,
        private readonly string $title = 'Kinetis API',
        private readonly string $version = '1.0.0',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $this->schemaNamesByClass = [];
        $this->componentSchemas = [];

        $paths = [];

        foreach ($this->router->routes() as $route) {
            if ($this->isHidden($route)) {
                continue;
            }

            $paths[$route->openApiPathTemplate()][strtolower($route->httpMethod)] = $this->describeOperation($route);
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
            ],
            'paths' => $paths,
        ];

        if ($this->componentSchemas !== []) {
            $document['components'] = ['schemas' => $this->componentSchemas];
        }

        return $document;
    }

    private function isHidden(Route $route): bool
    {
        $method = new ReflectionMethod($route->controllerClass, $route->controllerMethod);

        return $method->getAttributes(Hidden::class) !== []
            || new ReflectionClass($route->controllerClass)->getAttributes(Hidden::class) !== [];
    }

    /**
     * @phpstan-impure mutates $this->componentSchemas/$this->schemaNamesByClass
     *     (via schemaRefFor(), transitively through describeRequestBody()/
     *     describeDefaultResponse()) — annotated so PHPStan doesn't assume
     *     generate()'s $this->componentSchemas is still `[]` after this runs.
     * @return array<string, mixed>
     */
    private function describeOperation(Route $route): array
    {
        $method = new ReflectionMethod($route->controllerClass, $route->controllerMethod);
        $parameters = [];
        $requestBody = null;

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($parameter->getAttributes(Body::class) !== []) {
                $requestBody = $this->describeRequestBody($parameter);
                continue;
            }

            if ($parameter->getAttributes(Query::class) !== []) {
                $parameters[] = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => !$parameter->isDefaultValueAvailable(),
                    'schema' => JsonSchema::schemaForScalar($parameter, $parameter->getType()),
                ];
                continue;
            }

            if (in_array($name, $route->pathParameterNames(), true)) {
                $schema = JsonSchema::schemaForScalar($parameter, $parameter->getType());
                $constraintPattern = $route->pathParameterPattern($name);

                if ($constraintPattern !== null) {
                    $schema['pattern'] = $constraintPattern;
                }

                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $schema,
                ];
            }
        }

        $responses = [
            (string) $route->status => $this->describeDefaultResponse($method),
        ];

        foreach ($method->getAttributes(Response::class) as $attribute) {
            $response = $attribute->newInstance();

            // The route's own status is already described above, from the
            // method's return type, so an attribute repeating it is
            // ignored rather than allowed to replace that entry with a
            // description and no schema. #[Response] documents the
            // *additional* statuses a method can produce.
            if ($response->status() === $route->status) {
                continue;
            }

            $responses[(string) $response->status()] = ['description' => $response->description()];
        }

        $operation = ['responses' => $responses];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeRequestBody(ReflectionParameter $parameter): ?array
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string $dtoClass */
        $dtoClass = $type->getName();

        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => $this->schemaRefFor($dtoClass),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDefaultResponse(ReflectionMethod $method): array
    {
        $response = ['description' => 'Successful response'];
        $schema = $this->responseSchemaForType($method->getReturnType(), $method);

        if ($schema !== null) {
            $response['content'] = ['application/json' => ['schema' => $schema]];
        }

        return $response;
    }

    /**
     * A plain class return type (`UserResponse`), a nullable one
     * (`?UserResponse`), and a union (`ResponseInterface|array`, the
     * passthrough-status pattern documented on the class above) are all
     * handled: the union case resolves to whichever member is itself a
     * schema-producing class, since a raw ResponseInterface passthrough or
     * a bare array return has no shape reflection alone can recover. A
     * method with no declared return type, or one that resolves to nothing
     * schema-producing, gets no `content` key at all — the response stays
     * description-only.
     *
     * @return array<string, mixed>|null
     */
    private function responseSchemaForType(?ReflectionType $type, ReflectionMethod $method): ?array
    {
        if ($type instanceof ReflectionNamedType) {
            return $this->responseSchemaForNamedType($type, $method);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (!$member instanceof ReflectionNamedType) {
                    continue;
                }

                $schema = $this->responseSchemaForNamedType($member, $method);

                if ($schema !== null) {
                    return $schema;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseSchemaForNamedType(ReflectionNamedType $type, ReflectionMethod $method): ?array
    {
        if ($type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();

        if ($class === ResponseInterface::class) {
            // A raw passthrough response (a 404, a redirect, ...) has no
            // fixed shape to describe — see the class docblock.
            return null;
        }

        if ($class === Paginator::class || $class === CursorPaginator::class) {
            $paginatedSchema = $this->paginatedResponseSchema($class, $method);

            if ($paginatedSchema !== null) {
                return $paginatedSchema;
            }
        }

        /** @var class-string $class */
        return $this->schemaRefFor($class);
    }

    /**
     * Builds Paginator's/CursorPaginator's own shape inline — never
     * through schemaRefFor() for the wrapper itself, since a shared
     * "Paginator" component would incorrectly collapse two different
     * routes' different item types into one. Only the item class goes
     * through schemaRefFor(), so it's still deduped/$ref'd normally.
     * Returns null when the method carries no #[PaginatedItem] at all —
     * the caller then falls back to schemaRefFor()'s bare {type: object}
     * behavior for `data`.
     *
     * @param class-string $paginatorClass
     * @return array<string, mixed>|null
     */
    private function paginatedResponseSchema(string $paginatorClass, ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(PaginatedItem::class);

        if ($attributes === []) {
            return null;
        }

        $itemSchema = $this->schemaRefFor($attributes[0]->newInstance()->itemClass());
        $data = ['type' => 'array', 'items' => $itemSchema];

        $properties = $paginatorClass === Paginator::class
            ? [
                'data' => $data,
                'currentPage' => ['type' => 'integer'],
                'perPage' => ['type' => 'integer'],
                'total' => ['type' => 'integer'],
                'lastPage' => ['type' => 'integer'],
            ]
            : [
                'data' => $data,
                'nextCursor' => ['type' => 'string'],
                'hasMore' => ['type' => 'boolean'],
            ];

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * Registers $class's schema into components/schemas on first reference
     * (recursively, via JsonSchema's $classSchema callback, so a nested
     * DTO reached through this one is deduped too, at any depth) and
     * returns a $ref to it. A second reference to the same class — from a
     * different route, or a different field of the same route — reuses the
     * already-registered entry rather than describing it again.
     *
     * The name is registered *before* the recursive JsonSchema::forClass()
     * call below, not after: a self-referencing DTO's nested reference to
     * its own class hits the isset() check and returns a $ref immediately,
     * rather than recursing forever.
     *
     * @param class-string $class
     * @return array{'$ref': string}
     */
    private function schemaRefFor(string $class): array
    {
        if (!isset($this->schemaNamesByClass[$class])) {
            $name = $this->uniqueSchemaName($class);
            $this->schemaNamesByClass[$class] = $name;
            $this->componentSchemas[$name] = JsonSchema::forClass($class, $this->schemaRefFor(...));
        }

        return ['$ref' => '#/components/schemas/' . $this->schemaNamesByClass[$class]];
    }

    /**
     * The DTO's short class name is used as-is when it's not already taken
     * by a *different* class — the common case, and the more readable
     * component name. Two distinct classes sharing a short name (e.g. two
     * different `CreateRequest`s in different namespaces) fall back to the
     * fully-qualified name with `\` replaced by `.` (a valid OpenAPI
     * component-name character, `/` and `\` aren't) rather than silently
     * overwriting one DTO's schema with the other's.
     *
     * @param class-string $class
     */
    private function uniqueSchemaName(string $class): string
    {
        // A class in the global namespace has no separator to find, and
        // casting that `false` to an offset would drop its first
        // character.
        $separator = strrpos($class, '\\');
        $short = $separator === false ? $class : substr($class, $separator + 1);

        if (!in_array($short, $this->schemaNamesByClass, true)) {
            return $short;
        }

        return str_replace('\\', '.', $class);
    }
}
