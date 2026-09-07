<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Query;
use Kinetis\Http\Exception\MalformedRequestBodyException;
use Kinetis\Http\Exception\UnresolvableParameterException;
use Kinetis\Http\Exception\UnsupportedBodyMediaTypeException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Reflection\Exception\UnsupportedDefaultValueException;
use Kinetis\Reflection\ParameterDefault;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\RouteMatch;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonTree;
use Nyholm\Psr7\Response;
use Kinetis\Container\ResolutionAvailability;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * Binds each of a matched route's method parameters from the request
 * (#[Body] DTO, #[Query] scalar, a same-named path parameter, a
 * ServerRequestInterface-typed parameter that receives the raw request
 * directly, or an UploadedFileInterface-typed parameter pulled from the
 * request's uploaded-files bag by name), resolves the controller through
 * the container, invokes it, and encodes the return value as a JSON
 * PSR-7 response. Binding comes first so that a request rejected as a
 * 400, 415 or 422 never constructs the controller, and no constructor or
 * registered factory runs on its behalf. A #[Body] DTO is read from
 * getParsedBody() for multipart/form-data and
 * application/x-www-form-urlencoded, and decoded as JSON for
 * application/json and any application/*+json subtype, as
 * {@see MediaType} classifies them; a nonblank body under any other
 * media type — or under none at all — is a 415 raised before hydration.
 * A parameter typed ServerRequestInterface is untouched by that rule and
 * still receives any raw or binary body. An UploadedFileInterface-typed
 * constructor parameter on that same DTO needs no special handling in
 * Hydrator itself, since the files bag is merged into the data array
 * before hydration.
 *
 * $bindingPlans/$hydrationPlans are optional, compiled-ahead-of-time
 * replacements for what derivePlan()/Hydrator::compilePlan() would otherwise
 * reflect fresh on every call — see Kinetis\Cache\Compiler. A route or DTO
 * absent from either map falls back to live reflection transparently; the
 * cache never needs to be complete for correctness, only for speed.
 *
 * A #[Query]/path parameter's own Constraint attributes (#[GreaterThan],
 * #[In], ...) are captured in the plan and evaluated in
 * resolveScalarFromPlan(), after the declared-type-mismatch check and
 * cast — the same two-stage shape Hydrator uses for a #[Body] DTO
 * field, applied uniformly to every parameter source.
 *
 * A parameter's own default value is captured under the rule
 * Kinetis\Reflection\ParameterDefault owns, shared with Hydrator's
 * hydration plan: an object default other than an enum case is rejected
 * there, while the plan is derived.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-type HttpBindingPlan array{
 *     name: string,
 *     source: string,
 *     dtoClass: ?string, // the DTO for 'body', the service class for 'container'
 *     scalarType: ?string,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     allowsNull: bool,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 */
final class Dispatcher
{
    private const array BINDING_PLAN_KEYS = [
        'name', 'source', 'dtoClass', 'scalarType', 'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        /** @var array<string, list<HttpBindingPlan>> */
        private readonly array $bindingPlans = [],
        /** @var array<string, HydrationPlan> */
        private readonly array $hydrationPlans = [],
    ) {}

    public function dispatch(RouteMatch $match, ServerRequestInterface $request): ResponseInterface
    {
        $route = $match->route;
        $key = "{$route->controllerClass}::{$route->controllerMethod}";
        // The uncached plan reflects the controller *class string*, so no
        // instance is needed to derive it. That keeps container
        // resolution of the controller — and with it its constructor or
        // registered factory — behind the argument-binding step below, so
        // a request rejected as a 400/415/422 never constructs the
        // controller.
        $plan = $this->bindingPlans[$key]
            ?? self::derivePlan(new ReflectionMethod($route->controllerClass, $route->controllerMethod), $route);

        try {
            $arguments = $this->resolveFromPlan($plan, $match, $request);
        } catch (MalformedRequestBodyException $e) {
            return ErrorResponse::create(400, $e->getMessage());
        } catch (UnsupportedBodyMediaTypeException $e) {
            return ErrorResponse::create(415, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors], 422);
        }

        $controller = $this->container->get($route->controllerClass);

        // Router only ever registers public methods (getMethods(IS_PUBLIC)),
        // so a named-argument dynamic call is always legal here — and,
        // unlike ReflectionMethod::invokeArgs(), needs zero Reflection at
        // invocation time either, on top of the plan already avoiding it
        // for parameter derivation.
        $telemetry = Telemetry::global();
        $invokeToken = $telemetry->controllerInvoked($route->controllerClass, $route->controllerMethod);

        try {
            $result = $controller->{$route->controllerMethod}(...$arguments);
            $telemetry->controllerReturned($invokeToken, null);
        } catch (Throwable $e) {
            $telemetry->controllerReturned($invokeToken, $e);

            throw $e;
        }

        // A controller returning a ResponseInterface directly (a 404 when
        // a fetched entity doesn't exist, a 3xx redirect with a Location
        // header, ...) is passed through untouched instead of being
        // re-wrapped in $route->status — that fixed, route-level status is
        // only ever the *default* for a plain data return, not the only
        // status the method can produce.
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $encodeToken = $telemetry->responseEncodingStarted();

        try {
            return $this->json($result, $route->status);
        } finally {
            $telemetry->responseEncodingEnded($encodeToken);
        }
    }

    /**
     * Validates a compiled `array<string, list<HttpBindingPlan>>` map —
     * this class is the one abstraction that owns `HttpBindingPlan`'s
     * shape, so this is the one place that shape is ever checked,
     * called by `Kinetis\Cache\HttpCache::fromArray()` rather than that
     * class re-deriving the same rules itself. Every top-level key must
     * be a real string (PHP silently coerces a numeric-looking array key
     * to int); every value must be a list of entries, each with exactly
     * the eight fields `derivePlan()` itself always produces, correctly
     * typed. `defaultValue` is never checked beyond "the key is
     * present" — it holds an arbitrary PHP default value, which has no
     * single type to validate against.
     *
     * @param array<array-key, mixed> $plans
     * @throws CacheArtifactExceptionInterface
     */
    public static function validateBindingPlans(array $plans): void
    {
        foreach ($plans as $key => $entries) {
            if (!is_string($key)) {
                throw InvalidCacheArtifactException::malformedEntry('HttpBindingPlan', 'a key that is not a string');
            }

            if (!is_array($entries) || !array_is_list($entries)) {
                throw InvalidCacheArtifactException::wrongFieldType('HttpBindingPlan', $key, 'a list');
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw InvalidCacheArtifactException::malformedEntry('HttpBindingPlan', "a non-array entry for \"{$key}\"");
                }

                ArtifactValidation::exactKeys($entry, 'HttpBindingPlan', self::BINDING_PLAN_KEYS);

                ArtifactValidation::string($entry, 'HttpBindingPlan', 'name');
                ArtifactValidation::string($entry, 'HttpBindingPlan', 'source');
                ArtifactValidation::nullableString($entry, 'HttpBindingPlan', 'dtoClass');
                ArtifactValidation::nullableString($entry, 'HttpBindingPlan', 'scalarType');
                ArtifactValidation::bool($entry, 'HttpBindingPlan', 'hasDefault');
                ArtifactValidation::bool($entry, 'HttpBindingPlan', 'allowsNull');
                // defaultValue's own presence is already guaranteed by
                // exactKeys() above; its value holds an arbitrary PHP
                // default with no single type to check further.
                ArtifactValidation::listOfConstraintDescriptors($entry, 'HttpBindingPlan', 'constraints');
            }
        }
    }

    /**
     * Pure reflection -> plan; no request data involved, so the result is
     * identical for every call this route will ever receive. Used both by
     * the live per-request fallback above (when no compiled plan exists)
     * and by Kinetis\Cache\Compiler ahead of time — one derivation algorithm,
     * not two that could drift apart. Also where a required standalone-
     * `null`-typed #[Query]/path parameter — impossible for any request to
     * ever satisfy — is rejected; see
     * UnresolvableParameterException::forImpossibleQueryOrPathNull().
     *
     * @return list<HttpBindingPlan>
     * @throws UnresolvableParameterException
     * @throws UnsupportedDefaultValueException
     */
    public static function derivePlan(ReflectionMethod $method, Route $route): array
    {
        $plan = [];
        $pathParameterNames = $route->pathParameterNames();
        $owner = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            [$source, $dtoClass] = self::resolveSource($parameter, $name, $type, $pathParameterNames);
            $scalarType = $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null;

            // A standalone-`null`-typed #[Query]/path parameter can never
            // be satisfied by any request: query/path values are always
            // raw, non-empty strings when present, never PHP's real null.
            // A `#[Query]` field is rejected only when defaultless — a
            // defaulted one has a genuine working path, an *absent* query
            // key. A path parameter has no such path regardless of
            // whether it declares a default: a matched route's own
            // placeholder capture always supplies a real string, so
            // resolveScalarFromPlan()'s "value missing, use the default"
            // branch is unreachable dead code for a path source — the
            // rejection therefore applies unconditionally there. Either
            // way, every possible request to the affected route fails —
            // rejected here, at plan derivation, rather than silently
            // shipping a route that can never dispatch successfully.
            $nullQueryOrPathIsImpossible = $scalarType === 'null'
                && (($source === 'query' && !$parameter->isDefaultValueAvailable()) || $source === 'path');

            if ($nullQueryOrPathIsImpossible) {
                throw UnresolvableParameterException::forImpossibleQueryOrPathNull($name, $source);
            }

            // An array/iterable-typed path parameter is equally
            // impossible, unconditionally — unlike #[Query] (a repeated
            // query key, ?tags=a&tags=b, works, see "Query and path
            // values are raw strings" in routing-validation.md), a route
            // placeholder is always exactly one path segment, captured as
            // a single string. There is no repetition (or any other)
            // convention that could ever make a path segment become an
            // array.
            if (($scalarType === 'array' || $scalarType === 'iterable') && $source === 'path') {
                throw UnresolvableParameterException::forImpossiblePathArray($name);
            }

            $plan[] = [
                'name' => $name,
                'source' => $source,
                'dtoClass' => $dtoClass,
                // Already null for 'request'/'uploadedFile'/'body' without
                // special-casing here: none of those three types is ever
                // isBuiltin(), so $scalarType is already null by the time
                // any of those branches below is reached.
                'scalarType' => $scalarType,
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'defaultValue' => ParameterDefault::capture($parameter, $owner),
                // An untyped parameter accepts anything, null included.
                'allowsNull' => $type === null || $type->allowsNull(),
                // Only meaningful for 'query'/'path' — a #[Body] DTO's own
                // constraints are Hydrator's concern, not this method's.
                'constraints' => Hydrator::collectConstraints($parameter),
            ];
        }

        return $plan;
    }

    /**
     * @param list<string> $pathParameterNames
     * @return array{0:string, 1:?string}
     */
    private static function resolveSource(ReflectionParameter $parameter, string $name, ?ReflectionType $type, array $pathParameterNames): array
    {
        if ($type instanceof ReflectionNamedType && $type->getName() === ServerRequestInterface::class) {
            return ['request', null];
        }

        if ($type instanceof ReflectionNamedType && $type->getName() === UploadedFileInterface::class) {
            return ['uploadedFile', null];
        }

        if ($parameter->getAttributes(Body::class) !== []) {
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw UnresolvableParameterException::forParameter($name);
            }

            return ['body', $type->getName()];
        }

        if ($parameter->getAttributes(Query::class) !== []) {
            return ['query', null];
        }

        if (in_array($name, $pathParameterNames, true)) {
            return ['path', null];
        }

        // Anything class-typed left over comes from the request
        // container: a service, or — the case this exists for — a value
        // an earlier route middleware registered on the request scope.
        // Checked last, so it can never shadow #[Body], #[Query], or a
        // path parameter.
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return ['container', $type->getName()];
        }

        return ['default', null];
    }

    /**
     * The one resolution algorithm both the live and compiled paths share —
     * the only difference between them is how $plan was obtained.
     *
     * @param list<HttpBindingPlan> $plan
     * @return array<string, mixed>
     * @throws ValidationException
     * @throws MalformedRequestBodyException
     */
    private function resolveFromPlan(array $plan, RouteMatch $match, ServerRequestInterface $request): array
    {
        $arguments = [];
        $errors = [];

        foreach ($plan as $param) {
            $name = $param['name'];

            try {
                $arguments[$name] = match ($param['source']) {
                    'request' => $request,
                    'uploadedFile' => $this->resolveUploadedFileFromPlan($request->getUploadedFiles()[$name] ?? null, $name, $param),
                    'body' => $this->resolveBodyFromPlan($param, $request),
                    'query' => $this->resolveScalarFromPlan(self::rawQueryValue($request, $name, $param), $name, $param),
                    'path' => $this->resolveScalarFromPlan($match->pathParams[$name], $name, $param),
                    'container' => $this->resolveFromContainer($param),
                    default => $param['hasDefault'] ? $param['defaultValue'] : throw UnresolvableParameterException::forParameter($name),
                };
            } catch (ValidationException $e) {
                // Collected rather than rethrown immediately, so several
                // #[Query]/path type mismatches on the same route all
                // surface together in one 422 — the same "every error at
                // once" discipline Hydrator itself already applies within
                // a single DTO.
                foreach ($e->errors as $field => $messages) {
                    $errors[$field] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::forErrors($errors);
        }

        return $arguments;
    }

    /**
     * @param HttpBindingPlan $param
     * @throws ValidationException
     * @throws MalformedRequestBodyException
     * @throws UnsupportedBodyMediaTypeException
     */
    private function resolveBodyFromPlan(array $param, ServerRequestInterface $request): object
    {
        $contentType = $request->getHeaderLine('Content-Type');

        $formEncoded = MediaType::isFormEncoded($contentType);

        if ($formEncoded) {
            $decoded = $this->parsedBodyAsArray($request);
        } else {
            // Cast rather than getContents(): RequestBodyMiddleware has
            // staged a seekable, replayable body, and the cast is the
            // representation that rewinds first — so a middleware that
            // already inspected the body hands this the whole document
            // rather than the remainder past its cursor. Read once, so
            // the media-type check and the decoder see the same bytes.
            $body = (string) $request->getBody();

            // Blank under decodeJsonBody()'s own trim semantics keeps
            // its meaning of "no fields", whatever the header says — a
            // route with an all-optional DTO and a bodiless request has
            // nothing for a media type to describe. Anything else must
            // say it is JSON to be read as JSON.
            if (trim($body) !== '' && !MediaType::isJson($contentType)) {
                throw UnsupportedBodyMediaTypeException::forTypedBody();
            }

            $decoded = $this->decodeJsonBody($body);
        }

        /** @var class-string $dtoClass */
        $dtoClass = $param['dtoClass'];

        // A DTO constructor parameter typed UploadedFileInterface is an
        // ordinary non-instantiable class-typed field to Hydrator: it
        // accepts an existing instance of the declared interface and
        // nothing else, so merging the files bag in here is what makes
        // the field resolvable — Hydrator never needs to know files exist
        // at all. Left-wins union: a same-named regular field, if one
        // somehow exists, isn't silently overwritten by a file.
        $data = $decoded + $this->uploadedFilesByFieldName($request);

        $hydrationToken = Telemetry::global()->hydrationStarted($dtoClass);

        try {
            // normalizeFormLiterals is scoped to genuinely form-encoded
            // requests specifically — a JSON request for the identical
            // DTO class must keep rejecting a real "true"/"false" JSON
            // string the same way it always has; see Hydrator::hydrate()'s
            // own docblock for the full reasoning.
            return Hydrator::hydrate($dtoClass, $data, $this->hydrationPlans[$dtoClass] ?? null, normalizeFormLiterals: $formEncoded);
        } finally {
            Telemetry::global()->hydrationEnded($hydrationToken);
        }
    }

    /**
     * An empty body is treated as "no fields" — the same outcome a plain
     * `{}` body already produces — rather than an error, since a route
     * with an all-optional #[Body] DTO commonly expects exactly that. A
     * non-empty body must decode to a JSON *object*: a #[Body] parameter
     * is a DTO and a DTO's fields are named, so a top-level JSON array
     * is as malformed as null, a bare string, a bare number or a bare
     * bool, and all of them throw. This decoder is the only place that
     * distinction exists — `Hydrator` sees a field map, in which `[]`
     * and `{}` are the same value.
     *
     * Decoded with `associative: false`, not `true`, and run through
     * `JsonTree::convert()` — this is what lets `Hydrator::typeMismatchMessage()`'s
     * array/iterable/`#[ListOf]` checks reject a JSON *object* wherever an
     * array is declared, including one whose own keys happen to look
     * sequential (`{"0":"a","1":"b"}`), which `array_is_list()` alone
     * cannot distinguish from a real array once `associative: true` has
     * already collapsed both into the identical PHP shape. The top level
     * — a #[Body] DTO's own named fields — is always unwrapped back to a
     * plain array here; only values *nested* inside it stay marked.
     *
     * @return array<string, mixed>
     * @throws MalformedRequestBodyException
     */
    private function decodeJsonBody(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, associative: false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw MalformedRequestBodyException::invalidJson();
        }

        $converted = JsonTree::convert($decoded);

        if (!$converted instanceof JsonObject) {
            throw MalformedRequestBodyException::notAnObject();
        }

        return $converted->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedBodyAsArray(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * A request without the expected file resolves like a missing #[Query]
     * value: the default if one exists, null if the parameter accepts it,
     * and an "is required." entry in the route's 422 otherwise — a client
     * forgetting a file field is malformed input, not a server error.
     *
     * @param HttpBindingPlan $param
     * @throws ValidationException
     */
    private function resolveUploadedFileFromPlan(mixed $file, string $name, array $param): mixed
    {
        if ($file !== null) {
            return $file;
        }

        if ($param['hasDefault']) {
            return $param['defaultValue'];
        }

        if ($param['allowsNull']) {
            return null;
        }

        throw ValidationException::forErrors([$name => ['is required.']]);
    }

    /**
     * Flat, single-level only — matches Hydrator's own DTO-discovery
     * scanning discipline elsewhere. A nested (array-style, `photos[]`)
     * file input isn't merged here.
     *
     * @return array<string, UploadedFileInterface>
     */
    private function uploadedFilesByFieldName(ServerRequestInterface $request): array
    {
        $files = [];

        foreach ($request->getUploadedFiles() as $name => $file) {
            if ($file instanceof UploadedFileInterface) {
                $files[$name] = $file;
            }
        }

        return $files;
    }

    /**
     * A class-typed parameter, resolved from the request container.
     *
     * A default value, or a nullable type, says the dependency may be
     * absent — nothing registered the id and nothing could be built for
     * it. It never covers a dependency the container does have and
     * cannot supply: a service whose factory throws, a nested dependency
     * that will not build, a cycle, a disposed scope. Each of those is a
     * defect, and its own exception propagates rather than arriving at
     * the controller as null.
     *
     * Absence with nothing to stand in for it is reported against the
     * parameter rather than against whatever the container failed to
     * autowire: the useful fact is which route is missing which
     * middleware, not that some constructor deep inside wanted a string.
     *
     * Kinetis\Container\ResolutionAvailability draws the line, so a
     * dependency behaves the same here as it does in a constructor.
     *
     * @param HttpBindingPlan $param
     */
    private function resolveFromContainer(array $param): mixed
    {
        $class = $param['dtoClass'];

        if ($class === null) {
            throw UnresolvableParameterException::forParameter($param['name']);
        }

        if (ResolutionAvailability::canResolve($this->container, $class)) {
            return $this->container->get($class);
        }

        if ($param['hasDefault']) {
            return $param['defaultValue'];
        }

        if ($param['allowsNull']) {
            return null;
        }

        try {
            return $this->container->get($class);
        } catch (ContainerExceptionInterface $e) {
            throw UnresolvableParameterException::forContainerParameter($param['name'], $class, $e);
        }
    }

    /**
     * An array/iterable-typed #[Query] parameter is satisfied by exactly
     * one wire form: the repeated-key spelling `?tags=a&tags=b`, OpenAPI
     * 3.1's own *default* array serialization (`style: form`,
     * `explode: true` — never stated explicitly in the generated
     * document, since it's the spec default whenever neither is
     * overridden), which is what OpenApiGenerator advertises and what a
     * client generated from that document sends.
     *
     * PSR-7's own getQueryParams(), built by every runtime adapter from
     * PHP's native `parse_str()`, cannot represent that form at all: a
     * repeated, non-bracketed key silently collapses to its last value
     * there, with every earlier one lost and no error raised anywhere.
     * The values are parsed directly from the request's own raw,
     * unparsed query string instead, via repeatedQueryValues() below —
     * available identically on every runtime adapter through PSR-7's
     * UriInterface, so what's advertised works on every runtime with no
     * per-adapter change.
     *
     * PHP's bracket spelling, `?tags[]=a`, sends a different key: the
     * name on the wire is `tags[]`, not `tags`, so it satisfies no
     * #[Query('tags')] parameter and reaches the ordinary "value
     * missing" branch (default, then allowsNull, then "is required.")
     * like any other absent key.
     *
     * @param HttpBindingPlan $param
     */
    private static function rawQueryValue(ServerRequestInterface $request, string $name, array $param): mixed
    {
        if ($param['scalarType'] === 'array' || $param['scalarType'] === 'iterable') {
            return self::repeatedQueryValues($request->getUri()->getQuery(), $name);
        }

        return $request->getQueryParams()[$name] ?? null;
    }

    /**
     * A minimal, standards-based parser for exactly the one thing
     * PSR-7's getQueryParams() cannot represent: every value sent under
     * the *same*, non-bracketed key, in order. Deliberately not a
     * general query-string parser — it only ever collects values for
     * the one `$name` the caller is resolving, ignoring every other key
     * entirely, since that's the only thing an array/iterable-typed
     * #[Query] parameter's own binding ever needs.
     *
     * Returns `null` — never an empty array — when the key never appears
     * at all, so the caller's existing "value missing" branch (default,
     * then allowsNull, then "is required.") is reached exactly as it is
     * for any other absent #[Query] parameter. This never claims to
     * solve a different case: an explicitly-empty array has no wire
     * spelling in this convention at all (there's no way to distinguish
     * "the key was never sent" from "it was sent with zero values"), so
     * a caller wanting an always-populated empty array already gets one
     * from the parameter's own default value instead.
     *
     * @return ?list<string>
     */
    private static function repeatedQueryValues(string $rawQuery, string $name): ?array
    {
        if ($rawQuery === '') {
            return null;
        }

        $values = [];

        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = str_contains($pair, '=') ? explode('=', $pair, 2) : [$pair, ''];

            // '+' means a literal space in a query string/form body
            // (RFC 1866) — urldecode(), not rawurldecode(), is what
            // PHP's own parse_str() applies internally too, so a value
            // read here decodes exactly the way the same bytes would
            // under getQueryParams().
            if (urldecode($key) === $name) {
                $values[] = urldecode($value);
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * Applies the identical declared-type-mismatch check
     * Hydrator::typeMismatchMessage() runs for #[Body] DTO fields, before
     * casting — a #[Query]/path value with the wrong shape (an array for a
     * scalar param, a non-numeric string for an int/float one, ...) is a
     * 422, never a silently wrong cast (e.g. "not-a-number" -> 0). Once
     * cast, the parameter's own Constraint attributes run against the
     * cast value — the same #[GreaterThan]/#[In]/etc. attributes that
     * work on a #[Body] DTO field, honored identically here.
     *
     * The check itself is genuinely the same method regardless of source
     * — but a #[Query]/path *value* is not: it only ever arrives as a raw
     * string (or, for a #[Query] array-style parameter, a PHP array —
     * unaffected by the normalization below), never a real JSON-decoded
     * bool the way a request body's own `true`/`false` literal is. This
     * source-specific normalization step exists so the shared check still
     * receives a genuinely equivalent value, not a string standing in for
     * one; see normalizeQueryOrPathLiteral()'s own docblock.
     *
     * @param HttpBindingPlan $param
     * @throws ValidationException
     */
    private function resolveScalarFromPlan(mixed $raw, string $name, array $param): mixed
    {
        if ($raw === null) {
            if ($param['hasDefault']) {
                return $param['defaultValue'];
            }

            // A missing value can only legally become null if the parameter
            // actually accepts null — otherwise it would explode as a raw
            // TypeError at controller invocation instead of joining the
            // route's other binding errors in one 422.
            if (!$param['allowsNull']) {
                throw ValidationException::forErrors([$name => ['is required.']]);
            }

            return null;
        }

        $scalarType = $param['scalarType'];
        $raw = self::normalizeQueryOrPathLiteral($scalarType, $raw);

        if ($scalarType !== null) {
            $message = Hydrator::typeMismatchMessage($scalarType, $raw);

            if ($message !== null) {
                throw ValidationException::forErrors([$name => [$message]]);
            }
        }

        $value = Hydrator::castScalar($raw, $scalarType);

        $errors = [];

        foreach ($param['constraints'] as $descriptor) {
            /** @var class-string<Constraint> $constraintClass */
            $constraintClass = $descriptor['class'];
            $constraint = new $constraintClass(...$descriptor['args']);
            $message = $constraint->validate($value);

            if ($message !== null) {
                $errors[$name][] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::forErrors($errors);
        }

        return $value;
    }

    /**
     * A #[Query]/path value is a raw string when present, never PHP's
     * real `true`/`false` the way an already-decoded JSON body's own
     * boolean literal is — but OpenAPI's own query-serialization
     * convention for a boolean-shaped value documents exactly the
     * literal spellings "true"/"false" (the same spelling a JSON
     * boolean prints as), which is what a client generated from this
     * route's own schema actually sends. Translating those two spellings
     * into the real PHP `true`/`false` here — the one place a #[Query]/
     * path *source* genuinely differs from a JSON body — is what lets
     * Hydrator::typeMismatchMessage()'s shared check (built against
     * genuinely JSON-decoded values) treat them correctly, for both
     * `bool` and the narrower standalone `true`/`false` types. `bool`'s
     * own pre-existing `"1"`/`"0"` spellings are untouched — they already
     * pass typeMismatchMessage()'s check as raw strings, unaffected by
     * this. Anything else — including the list a #[Query] array-style
     * parameter (`?tags=a&tags=b`) produces — passes through unchanged.
     */
    private static function normalizeQueryOrPathLiteral(?string $scalarType, mixed $raw): mixed
    {
        if (!in_array($scalarType, ['bool', 'true', 'false'], true) || !is_string($raw)) {
            return $raw;
        }

        return match ($raw) {
            'true' => true,
            'false' => false,
            default => $raw,
        };
    }

    private function json(mixed $data, int $status): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
