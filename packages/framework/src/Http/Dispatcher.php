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
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonTree;
use Nyholm\Psr7\Response;
use Kinetis\Container\Autowire;
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
 * request's normalized uploaded files by name), resolves the controller
 * through the container, invokes it, and encodes the return value as a JSON
 * PSR-7 response. Binding comes first so that a request rejected as a
 * 400 or 415, or refused by validation, never constructs the
 * controller, and no constructor or registered factory runs on its
 * behalf. A #[Body] DTO is read from
 * getParsedBody() for multipart/form-data and
 * application/x-www-form-urlencoded, and decoded as JSON for
 * application/json and any application/*+json subtype, as
 * {@see MediaType} classifies them; a nonblank body under any other
 * media type — or under none at all — is a 415 raised before hydration.
 * A parameter typed ServerRequestInterface is untouched by that rule and
 * still receives any raw or binary body.
 *
 * This class owns the request's uploaded files as *transport*. Once per
 * dispatch, normalizeUploads() turns getUploadedFiles() into the tree
 * binding sees — empty file controls dropped, emptied branches dropped,
 * pruned flat file lists closed back up while a branch of sub-branches
 * keeps its positions — and both file-reading sources read that
 * one tree: a form-encoded #[Body] DTO through mergeUploads(), which
 * folds it into the parsed text at every depth, and an
 * UploadedFileInterface-typed parameter through its own top-level name.
 * What each of them then *is* — a real file, a failed transfer, a value
 * that is no file at all — is Hydrator's answer, given once in
 * Hydrator::resolveUploadedFile() for both.
 *
 * $bindingPlans/$hydrationPlans are optional, compiled-ahead-of-time
 * replacements for what derivePlan()/Hydrator::compilePlan() would otherwise
 * reflect fresh on every call — see Kinetis\Cache\Compiler. A route or DTO
 * absent from either map falls back to live reflection transparently; the
 * cache never needs to be complete for correctness, only for speed.
 *
 * A parameter's own Constraint attributes (#[GreaterThan], #[In],
 * #[FileExtension], ...) are captured in the plan and evaluated after
 * whatever check establishes the value's shape: in
 * resolveScalarFromPlan() for a #[Query]/path parameter, after the
 * declared-type-mismatch check and cast, and in
 * Hydrator::resolveUploadedFile() for an uploaded file, after its
 * transport status. The same two-stage shape Hydrator uses for a
 * #[Body] DTO field, applied uniformly to every parameter source.
 *
 * A binding failure from any source leaves this class as the
 * Kinetis\Validation\Exception\ValidationException it is, carrying every
 * violation the whole plan produced. It is not turned into a response
 * here: route and application middleware get to catch it — one storing
 * flash errors and redirecting, say, while the session is still alive —
 * and whatever reaches the terminal
 * Kinetis\Http\Middleware\ExceptionHandlerMiddleware is rendered by the
 * application's own Kinetis\Http\ValidationExceptionRendererInterface.
 * A 400 or 415 stays here: neither carries per-field structure, and
 * neither is a candidate for that seam.
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
 *     bodyRoot: ?string, // the top-level member a 'body' DTO is read from; null reads the whole document
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
        'name', 'source', 'dtoClass', 'bodyRoot', 'scalarType', 'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
    ];

    public function __construct(
        private readonly ContainerInterface $container,
        /** @var array<string, list<HttpBindingPlan>> */
        private readonly array $bindingPlans = [],
        /** @var array<string, HydrationPlan> */
        private readonly array $hydrationPlans = [],
    ) {}

    /**
     * @throws ValidationException
     */
    public function dispatch(RouteMatch $match, ServerRequestInterface $request): ResponseInterface
    {
        $route = $match->route;
        $key = "{$route->controllerClass}::{$route->controllerMethod}";
        // The uncached plan reflects the controller *class string*, so no
        // instance is needed to derive it. That keeps container
        // resolution of the controller — and with it its constructor or
        // registered factory — behind the argument-binding step below, so
        // a request rejected as a 400/415, or refused by validation,
        // never constructs the controller.
        $plan = $this->bindingPlans[$key]
            ?? self::derivePlan(new ReflectionMethod($route->controllerClass, $route->controllerMethod), $route);

        try {
            $arguments = $this->resolveFromPlan($plan, $match, $request);
        } catch (MalformedRequestBodyException $e) {
            return ErrorResponse::create(400, $e->getMessage());
        } catch (UnsupportedBodyMediaTypeException $e) {
            return ErrorResponse::create(415, $e->getMessage());
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
     * the nine fields `derivePlan()` itself always produces, correctly
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
                ArtifactValidation::nullableString($entry, 'HttpBindingPlan', 'bodyRoot');
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
     * not two that could drift apart. Also where a #[Query]/path parameter
     * no request value could satisfy is rejected, and where a second
     * #[Body] parameter or an empty #[Body] root is.
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
        $bodyParameter = null;

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            [$source, $dtoClass] = self::resolveSource($parameter, $name, $type, $pathParameterNames);
            $scalarType = $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null;
            $bodyRoot = null;

            // A request carries one document, and every #[Body]
            // parameter validates its outer object as its own — under
            // JSON a rooted one refuses each member but its root — so a
            // second one could never accept the same request as the
            // first.
            if ($source === 'body') {
                if ($bodyParameter !== null) {
                    throw UnresolvableParameterException::forSecondBodyParameter($name, $bodyParameter);
                }

                $bodyParameter = $name;
                $bodyRoot = $parameter->getAttributes(Body::class)[0]->newInstance()->root();

                if ($bodyRoot === '') {
                    throw UnresolvableParameterException::forEmptyBodyRoot($name);
                }
            }

            // Only a query or path parameter reads request input here. A
            // 'default'-source parameter is filled from its own default
            // value and never touches the request, so any legal type
            // stays legal for it.
            $readsRequestInput = $source === 'query' || $source === 'path';

            if ($readsRequestInput && $scalarType !== null && !in_array($scalarType, Hydrator::SUPPORTED_BUILTIN_TYPES, true)) {
                throw UnresolvableParameterException::forUnsupportedBuiltinType($name, $source, $scalarType);
            }

            // A composite type on a request-reading parameter binds
            // nothing truthfully: a query string and a path segment carry
            // one value, in one shape, and a union declares more than one
            // — including the `T|Absent` presence union, which is a DTO
            // constructor field's contract and not a controller
            // signature's. Left unchecked, such a parameter would report
            // no scalar type at all and quietly bind like `mixed`, which
            // its own OpenAPI schema would then contradict.
            if ($readsRequestInput && $type !== null && !$type instanceof ReflectionNamedType) {
                throw UnresolvableParameterException::forCompositeType($name, $source);
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
                'bodyRoot' => $bodyRoot,
                // Already null for 'request'/'uploadedFile'/'body' without
                // special-casing here: none of those three types is ever
                // isBuiltin(), so $scalarType is already null by the time
                // any of those branches below is reached.
                'scalarType' => $scalarType,
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'defaultValue' => ParameterDefault::capture($parameter, $owner),
                // An untyped parameter accepts anything, null included.
                'allowsNull' => $type === null || $type->allowsNull(),
                // Only meaningful for 'query'/'path'/'uploadedFile' — a
                // #[Body] DTO's own field constraints are Hydrator's
                // concern, not this method's.
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
     * @throws UnsupportedBodyMediaTypeException
     */
    private function resolveFromPlan(array $plan, RouteMatch $match, ServerRequestInterface $request): array
    {
        $arguments = [];
        $violations = [];
        // Normalized once for the whole plan, not per parameter: a
        // route binding both a #[Body] DTO and a direct file parameter
        // reads one tree, so the two can never disagree about which
        // files this request actually carries.
        $uploads = self::normalizeUploads($request->getUploadedFiles()) ?? [];

        foreach ($plan as $param) {
            $name = $param['name'];

            try {
                $arguments[$name] = match ($param['source']) {
                    'request' => $request,
                    'uploadedFile' => $this->resolveUploadedFileFromPlan($uploads[$name] ?? null, $name, $param),
                    'body' => $this->resolveBodyFromPlan($param, $request, $uploads),
                    'query' => $this->resolveScalarFromPlan(self::rawQueryValue($request, $name, $param), $name, $param),
                    'path' => $this->resolveScalarFromPlan($match->pathParams[$name], $name, $param),
                    'container' => $this->resolveFromContainer($param),
                    default => $param['hasDefault'] ? $param['defaultValue'] : throw UnresolvableParameterException::forParameter($name),
                };
            } catch (ValidationException $e) {
                // Collected rather than rethrown immediately, so several
                // #[Query]/path type mismatches on the same route all
                // surface together in one failure — the same "every
                // violation at once" discipline Hydrator itself already
                // applies within a single DTO.
                $violations = [...$violations, ...$e->violations];
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $arguments;
    }

    /**
     * @param HttpBindingPlan $param
     * @param array<array-key, mixed> $uploads the request's normalized uploaded files
     * @throws ValidationException
     * @throws MalformedRequestBodyException
     * @throws UnsupportedBodyMediaTypeException
     */
    private function resolveBodyFromPlan(array $param, ServerRequestInterface $request, array $uploads): object
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

        // Only a form body carries files. A JSON document names every
        // value it sends, and merging an uploaded file into it would
        // bind a field the document never mentioned.
        /** @var array<string, mixed> $data */
        $data = $formEncoded ? self::mergeUploads($decoded, $uploads) : $decoded;

        // The source is the request's own, not the route's: the same
        // #[Body] DTO class binds a JSON document and a form body on the
        // same route, and only the content type the client actually sent
        // says which vocabulary its values are written in. A JSON request
        // keeps rejecting the JSON string "true" for a bool field; a form
        // body, which has no other spelling, binds it.
        $source = $formEncoded ? InputSource::Text : InputSource::Json;
        $plan = $this->hydrationPlans[$dtoClass] ?? null;

        $hydrationToken = Telemetry::global()->hydrationStarted($dtoClass);

        try {
            return $param['bodyRoot'] === null
                ? Hydrator::hydrate($dtoClass, $data, $plan, $source)
                : self::hydrateBodyRoot($param['bodyRoot'], $dtoClass, $data, $plan, $source);
        } finally {
            Telemetry::global()->hydrationEnded($hydrationToken);
        }
    }

    /**
     * A #[Body('root')] DTO, hydrated from the one top-level member its
     * root names. The document around that member is still read under
     * the request's own source: under InputSource::Json it is closed
     * exactly as a DTO's own object is, so every other member is
     * `unexpected_field` on its own path, after the root's own failures;
     * a form body stays open, as it does inside a DTO.
     *
     * Presence and null are decided here, where the member was read. The
     * member's shape and its nested failures are
     * Hydrator::resolveDtoValue()'s answer, so a rooted DTO reports what
     * a nested DTO field reports, under the root's path.
     *
     * @param class-string $dtoClass
     * @param array<array-key, mixed> $data
     * @param HydrationPlan|null $plan
     * @throws ValidationException
     */
    private static function hydrateBodyRoot(string $root, string $dtoClass, array $data, ?array $plan, InputSource $source): object
    {
        [$value, $violations] = match (true) {
            !array_key_exists($root, $data) => [null, [Hydrator::requiredViolation([$root])]],
            $data[$root] === null => [null, [Hydrator::nullNotAllowedViolation([$root])]],
            default => Hydrator::resolveDtoValue($source, [$root], $data[$root], $dtoClass, $plan),
        };

        if ($source === InputSource::Json) {
            foreach (array_keys($data) as $member) {
                // A numeric member name is an int key once decoded.
                if ((string) $member !== $root) {
                    $violations[] = Hydrator::unexpectedFieldViolation([$member]);
                }
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        /** @var object $value */
        return $value;
    }

    /**
     * An empty body is treated as a document with no members — the same
     * outcome a plain `{}` body already produces — rather than an error:
     * an all-optional #[Body] DTO hydrates from its own defaults, and a
     * rooted one reports its root as required. A non-empty body must
     * decode to a JSON *object*: a #[Body] document names its members —
     * a DTO's fields, or the root member holding them — so a top-level
     * JSON array is as malformed as null, a bare string, a bare number or
     * a bare bool, and all of them throw. This decoder is the only place
     * that distinction exists — `Hydrator` sees a field map, in which `[]`
     * and `{}` are the same value.
     *
     * Decoded with `associative: false`, not `true`, and run through
     * `JsonTree::convert()` — this is what lets `Hydrator`'s
     * array/iterable/`#[ListOf]` checks reject a JSON *object* wherever an
     * array is declared, including one whose own keys happen to look
     * sequential (`{"0":"a","1":"b"}`), which `array_is_list()` alone
     * cannot distinguish from a real array once `associative: true` has
     * already collapsed both into the identical PHP shape. The top level
     * — the document's own named members — is always unwrapped back to a
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
     * and an "is required." violation otherwise — a client forgetting a
     * file field, or submitting an empty file control, is malformed input,
     * not a server error.
     *
     * A file that is present goes through the same
     * Hydrator::resolveUploadedFile() a #[Body] DTO's own upload field
     * does, so the parameter's own Constraint attributes — captured by
     * derivePlan() like every other parameter's — actually run, after
     * the transport status has been checked.
     *
     * @param HttpBindingPlan $param
     * @throws ValidationException
     */
    private function resolveUploadedFileFromPlan(mixed $file, string $name, array $param): mixed
    {
        if ($file === null) {
            if ($param['hasDefault']) {
                return $param['defaultValue'];
            }

            if ($param['allowsNull']) {
                return null;
            }

            throw ValidationException::fromViolations([Hydrator::requiredViolation([$name])]);
        }

        [$resolved, $violations] = Hydrator::resolveUploadedFile([$name], $file, $param['constraints']);

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $resolved;
    }

    /**
     * The request's uploaded-files tree as binding sees it: every leaf
     * a real file, every branch one that still holds something.
     *
     * A browser submits an *empty* file control as a present
     * UPLOAD_ERR_NO_FILE part, not as nothing at all — a leaf whose
     * stream throws the moment anything reads it. Ordinary omission is
     * what that means, so such a leaf is dropped here, before any plan
     * can bind it: a required field then reports "is required." and a
     * defaulted or nullable one gets its default, exactly as a text
     * field the form never sent does.
     *
     * A branch left empty by that pruning is dropped in turn, and
     * answers null rather than `[]`. The distinction is the point: `[]`
     * is a supplied empty file list, which a `#[ListOf]` field would
     * bind and a #[MinItems] rule would then measure, and a form whose
     * every file control was left empty supplied no list at all.
     *
     * A pruned *flat* list is closed back up: a list-shaped branch
     * whose every child is a file gets array_values(), so `photos[]`
     * sent as file, empty, file binds two files at 0 and 1. A branch
     * that named a sub-branch keeps its keys even when it was
     * list-shaped, and whether that sub-branch survived pruning or not,
     * because there an index is a position the parsed text names too:
     * `entries[1][image]` left empty while `entries[2][image]` arrived
     * must keep the third file at 2, or mergeUploads() would fold it
     * into the second entry's text. A map-shaped branch keeps its keys,
     * which are field names a DTO declares and never positions.
     *
     * This is binding's view alone. The PSR-7 request keeps the bag its
     * runtime adapter built, NO_FILE parts included.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>|null null when nothing survived
     */
    private static function normalizeUploads(array $files): ?array
    {
        $wasList = array_is_list($files);
        $normalized = [];
        $structural = false;

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $structural = true;
                $branch = self::normalizeUploads($file);

                if ($branch !== null) {
                    $normalized[$key] = $branch;
                }

                continue;
            }

            if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[$key] = $file;
        }

        if ($normalized === []) {
            return null;
        }

        return $wasList && !$structural ? array_values($normalized) : $normalized;
    }

    /**
     * The form's text values and its normalized files as one field map,
     * which is the shape Hydrator hydrates a #[Body] DTO from: a
     * multipart form sends both, PHP parses each into its own tree by
     * the same bracket convention, and a DTO declares one field per
     * name regardless of which tree carried it. That is what lets a
     * nested `profile[name]` text field and a `profile[avatar]` file
     * hydrate the same nested DTO.
     *
     * One rule at every key, applied recursively: two arrays merge,
     * and anything else leaves the parsed text in place. A file-only
     * key is added. Text winning is not a preference between two
     * plausible values — a form naming one key as both text and file
     * has already contradicted itself, and keeping the text makes it
     * the ordinary declared-type violation the field would report for
     * any other wrong value, rather than a silently chosen winner.
     *
     * @param array<array-key, mixed> $data parsed text values
     * @param array<array-key, mixed> $files normalized uploaded files
     * @return array<array-key, mixed>
     */
    private static function mergeUploads(array $data, array $files): array
    {
        foreach ($files as $key => $file) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $file;

                continue;
            }

            if (is_array($data[$key]) && is_array($file)) {
                $data[$key] = self::mergeUploads($data[$key], $file);
            }
        }

        return $data;
    }

    /**
     * A class-typed parameter, resolved from the request container.
     *
     * A default value, or a nullable type, stands in for an absent
     * dependency and never for a broken one — the same rule constructor
     * autowiring applies, so moving a dependency between a constructor
     * and a method signature never changes what happens when it breaks.
     *
     * Absence with nothing to stand in for it is reported against the
     * parameter rather than against whatever the container failed to
     * autowire: the useful fact is which route is missing which
     * middleware, not that some constructor deep inside wanted a string.
     *
     * @param HttpBindingPlan $param
     */
    private function resolveFromContainer(array $param): mixed
    {
        $class = $param['dtoClass'];

        if ($class === null) {
            throw UnresolvableParameterException::forParameter($param['name']);
        }

        if (Autowire::isAvailable($this->container, $class)) {
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
     * Presence first, then Hydrator::resolveScalar() — the one path a
     * #[Body] DTO field and an MCP tool argument also take, so the type
     * check, the cast and the parameter's own #[GreaterThan]/#[In]/etc.
     * attributes are the same code producing the same violations, never
     * a second copy of the rules maintained beside them. A #[Query]/path
     * value with the wrong shape (an array for a scalar param, a
     * non-numeric string for an int/float one) is a violation, never a
     * silently wrong cast (`"not-a-number"` -> `0`).
     *
     * Presence stays here because only this method can tell what an
     * absent value means: a query key that never appeared and a path
     * segment are both read as `null`, and a #[Query] parameter that
     * accepts null takes it, where a DTO member's own absence is `is
     * required.` unless it has a default.
     *
     * InputSource::Text is unconditional: a query string and a path
     * segment carry text and nothing else, on every request, whatever
     * the body's content type says about the body.
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
            // route's other binding violations in one failure.
            if (!$param['allowsNull']) {
                throw ValidationException::fromViolations([Hydrator::requiredViolation([$name])]);
            }

            return null;
        }

        [$value, $violations] = Hydrator::resolveScalar(
            InputSource::Text,
            [$name],
            $raw,
            $param['scalarType'],
            $param['allowsNull'],
            $param['constraints'],
        );

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $value;
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
