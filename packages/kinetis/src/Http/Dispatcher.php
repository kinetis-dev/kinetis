<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Query;
use Kinetis\Http\Exception\UnresolvableParameterException;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\RouteMatch;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Nyholm\Psr7\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * Resolves a matched route's controller through the container, binds each
 * method parameter from the request (#[Body] DTO, #[Query] scalar, a
 * same-named path parameter, a ServerRequestInterface-typed parameter that
 * receives the raw request directly, or an UploadedFileInterface-typed
 * parameter pulled from the request's uploaded-files bag by name), invokes
 * it, and encodes the return value as a JSON PSR-7 response. A #[Body] DTO
 * is decoded as JSON by default, or read from getParsedBody() for
 * multipart/form-data and application/x-www-form-urlencoded — an
 * UploadedFileInterface-typed constructor parameter on that same DTO needs
 * no special handling in Hydrator itself, since the files bag is merged
 * into the data array before hydration. A failed #[Body] validation
 * short-circuits into a 422 response instead of ever reaching the
 * controller.
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
 * cast — the same two-stage shape Hydrator already uses for a #[Body]
 * DTO field. Previously silently ignored entirely outside #[Body].
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-type HttpBindingPlan array{
 *     name: string,
 *     source: string,
 *     dtoClass: ?string,
 *     scalarType: ?string,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 */
final class Dispatcher
{
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
        $controller = $this->container->get($route->controllerClass);
        $key = "{$route->controllerClass}::{$route->controllerMethod}";
        $plan = $this->bindingPlans[$key]
            ?? self::derivePlan(new ReflectionMethod($controller, $route->controllerMethod), $route);

        try {
            $arguments = $this->resolveFromPlan($plan, $match, $request);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors], 422);
        }

        // Router only ever registers public methods (getMethods(IS_PUBLIC)),
        // so a named-argument dynamic call is always legal here — and,
        // unlike ReflectionMethod::invokeArgs(), needs zero Reflection at
        // invocation time either, on top of the plan already avoiding it
        // for parameter derivation.
        $result = $controller->{$route->controllerMethod}(...$arguments);

        // A controller returning a ResponseInterface directly (a 404 when
        // a fetched entity doesn't exist, a 3xx redirect with a Location
        // header, ...) is passed through untouched instead of being
        // re-wrapped in $route->status — that fixed, route-level status is
        // only ever the *default* for a plain data return, not the only
        // status the method can produce.
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->json($result, $route->status);
    }

    /**
     * Pure reflection -> plan; no request data involved, so the result is
     * identical for every call this route will ever receive. Used both by
     * the live per-request fallback above (when no compiled plan exists)
     * and by Kinetis\Cache\Compiler ahead of time — one derivation algorithm,
     * not two that could drift apart.
     *
     * @return list<HttpBindingPlan>
     */
    public static function derivePlan(ReflectionMethod $method, Route $route): array
    {
        $plan = [];
        $pathParameterNames = $route->pathParameterNames();

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            [$source, $dtoClass] = self::resolveSource($parameter, $name, $type, $pathParameterNames);

            $plan[] = [
                'name' => $name,
                'source' => $source,
                'dtoClass' => $dtoClass,
                // Already null for 'request'/'uploadedFile'/'body' without
                // special-casing here: none of those three types is ever
                // isBuiltin(), so $scalarType is already null by the time
                // any of those branches below is reached.
                'scalarType' => $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null,
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'defaultValue' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
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

        return ['default', null];
    }

    /**
     * The one resolution algorithm both the live and compiled paths share —
     * the only difference between them is how $plan was obtained.
     *
     * @param list<HttpBindingPlan> $plan
     * @return array<string, mixed>
     * @throws ValidationException
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
                    'uploadedFile' => $request->getUploadedFiles()[$name]
                        ?? ($param['hasDefault'] ? $param['defaultValue'] : throw UnresolvableParameterException::forParameter($name)),
                    'body' => $this->resolveBodyFromPlan($param, $request),
                    'query' => $this->resolveScalarFromPlan($request->getQueryParams()[$name] ?? null, $name, $param),
                    'path' => $this->resolveScalarFromPlan($match->pathParams[$name], $name, $param),
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
     */
    private function resolveBodyFromPlan(array $param, ServerRequestInterface $request): object
    {
        $contentType = $request->getHeaderLine('Content-Type');

        $decoded = self::isFormEncoded($contentType)
            ? $this->parsedBodyAsArray($request)
            : json_decode((string) $request->getBody(), associative: true);

        /** @var class-string $dtoClass */
        $dtoClass = $param['dtoClass'];

        // A DTO constructor parameter typed UploadedFileInterface needs no
        // special-casing inside Hydrator: castScalar() already passes any
        // non-scalar-typed value through unchanged, so merging the files
        // bag in here is sufficient — Hydrator never needs to know files
        // exist at all. Left-wins union: a same-named regular field, if
        // one somehow exists, isn't silently overwritten by a file.
        $data = (is_array($decoded) ? $decoded : []) + $this->uploadedFilesByFieldName($request);

        return Hydrator::hydrate($dtoClass, $data, $this->hydrationPlans[$dtoClass] ?? null);
    }

    private static function isFormEncoded(string $contentType): bool
    {
        return str_starts_with($contentType, 'multipart/form-data')
            || str_starts_with($contentType, 'application/x-www-form-urlencoded');
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
     * Applies the identical declared-type-mismatch check
     * Hydrator::typeMismatchMessage() runs for #[Body] DTO fields, before
     * casting — a #[Query]/path value with the wrong shape (an array for a
     * scalar param, a non-numeric string for an int/float one, ...) is now
     * a 422 instead of a silently wrong cast (e.g. "not-a-number" -> 0).
     * Once cast, the parameter's own Constraint attributes run against the
     * cast value — the same #[GreaterThan]/#[In]/etc. attributes that
     * already work on a #[Body] DTO field, previously a silent no-op
     * anywhere else.
     *
     * @param HttpBindingPlan $param
     * @throws ValidationException
     */
    private function resolveScalarFromPlan(mixed $raw, string $name, array $param): mixed
    {
        if ($raw === null) {
            return $param['hasDefault'] ? $param['defaultValue'] : null;
        }

        $scalarType = $param['scalarType'];

        if ($scalarType !== null) {
            $message = Hydrator::typeMismatchMessage($scalarType, $raw);

            if ($message !== null) {
                throw ValidationException::forErrors([$name => [$message]]);
            }
        }

        $value = match ($scalarType) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'bool' => (bool) $raw,
            'string' => (string) $raw,
            default => $raw,
        };

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

    private function json(mixed $data, int $status): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
