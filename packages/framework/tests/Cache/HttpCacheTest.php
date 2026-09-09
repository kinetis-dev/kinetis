<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\HttpCache;
use PHPUnit\Framework\TestCase;

final class HttpCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_every_field_including_mixed_default_value_types(): void
    {
        $cache = new HttpCache(
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/users', 'controllerClass' => 'App\\C', 'controllerMethod' => 'index', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [
                'App\\C::index' => [
                    ['name' => 'page', 'source' => 'query', 'dtoClass' => null, 'scalarType' => 'int', 'hasDefault' => true, 'defaultValue' => 1, 'allowsNull' => false, 'constraints' => []],
                    ['name' => 'flag', 'source' => 'query', 'dtoClass' => null, 'scalarType' => 'bool', 'hasDefault' => true, 'defaultValue' => false, 'allowsNull' => false, 'constraints' => []],
                    ['name' => 'label', 'source' => 'default', 'dtoClass' => null, 'scalarType' => 'string', 'hasDefault' => true, 'defaultValue' => null, 'allowsNull' => true, 'constraints' => []],
                ],
            ],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [
                        [
                            'name' => 'name', 'scalarType' => 'string', 'enumClass' => null, 'dtoClass' => null, 'nestedPlan' => null,
                            'listItem' => null, 'objectMap' => false, 'absent' => false,
                            'hasDefault' => false, 'defaultValue' => null,
                            'allowsNull' => false,
                            'constraints' => [
                                ['class' => 'Kinetis\\Validation\\Constraints\\MinLength', 'args' => [3]],
                            ],
                        ],
                    ],
                    'objectRules' => [
                        ['class' => 'Kinetis\\Validation\\ObjectConstraints\\AtLeastOneProvided', 'args' => ['name']],
                    ],
                ],
            ],
            globalMiddleware: ['App\\RequestIdMiddleware'],
            openApiMiddleware: ['App\\OpenApiAuthMiddleware'],
            middlewareGroups: ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']],
        );

        $reconstructed = HttpCache::fromArray($cache->toArray());

        self::assertEquals($cache, $reconstructed);
        self::assertTrue($reconstructed->hydrationPlans['App\\Dto']['parameters'][0]['absent'] === false);
        self::assertSame(
            [['class' => 'Kinetis\\Validation\\ObjectConstraints\\AtLeastOneProvided', 'args' => ['name']]],
            $reconstructed->hydrationPlans['App\\Dto']['objectRules'],
        );
        self::assertSame(1, $reconstructed->httpBindingPlans['App\\C::index'][0]['defaultValue']);
        self::assertSame(false, $reconstructed->httpBindingPlans['App\\C::index'][1]['defaultValue']);
        self::assertNull($reconstructed->httpBindingPlans['App\\C::index'][2]['defaultValue']);
        self::assertSame(['App\\RequestIdMiddleware'], $reconstructed->globalMiddleware);
        self::assertSame(['App\\OpenApiAuthMiddleware'], $reconstructed->openApiMiddleware);
        self::assertSame(
            ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']],
            $reconstructed->middlewareGroups,
        );
    }

    public function test_var_export_round_trip_via_a_real_generated_file_preserves_shape(): void
    {
        $cache = new HttpCache(
            routes: [],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            openApiMiddleware: [],
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'kinetis_http_cache_test_') . '.php';
        file_put_contents($tmpFile, '<?php return ' . var_export($cache->toArray(), true) . ';');

        try {
            /** @var array<string, mixed> $data */
            $data = require $tmpFile;
            self::assertEquals($cache, HttpCache::fromArray($data));
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        return (new HttpCache(
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/x', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [
                'App\\C::m' => [
                    ['name' => 'id', 'source' => 'query', 'dtoClass' => null, 'scalarType' => 'int', 'hasDefault' => false, 'defaultValue' => null, 'allowsNull' => false, 'constraints' => []],
                ],
            ],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [
                        [
                            'name' => 'name', 'scalarType' => 'string', 'enumClass' => null, 'dtoClass' => null, 'nestedPlan' => null,
                            'listItem' => null, 'objectMap' => false, 'absent' => false,
                            'hasDefault' => false, 'defaultValue' => null,
                            'allowsNull' => false, 'constraints' => [],
                        ],
                    ],
                    'objectRules' => [],
                ],
            ],
            globalMiddleware: [],
            openApiMiddleware: [],
        ))->toArray();
    }

    public function test_from_array_rejects_an_unexpected_top_level_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray([...$this->validData(), 'extra' => 'nope']);
    }

    public function test_from_array_rejects_a_route_entry_with_an_unexpected_extra_field(): void
    {
        $data = $this->validData();
        $data['routes'][0]['extra'] = 'nope';

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_non_string_entry_in_global_middleware(): void
    {
        $data = $this->validData();
        $data['globalMiddleware'] = [42];

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_middleware_group_whose_value_is_not_a_list(): void
    {
        $data = $this->validData();
        $data['middlewareGroups'] = ['admin' => 'not-a-list'];

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_binding_plan_entry_missing_a_required_field(): void
    {
        $data = $this->validData();
        unset($data['httpBindingPlans']['App\\C::m'][0]['allowsNull']);

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_hydration_plan_missing_a_required_field(): void
    {
        $data = $this->validData();
        unset($data['hydrationPlans']['App\\Dto']['hasConstructor']);

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    /**
     * The two fields the presence union and DTO-level rules added. An
     * artifact written before either existed carries neither, which is
     * exactly the stale shape a format bump exists to keep out — and a
     * missing field must be refused rather than read as `false`/`[]` on
     * a production request.
     */
    public function test_from_array_rejects_a_hydration_plan_missing_its_object_rules(): void
    {
        $data = $this->validData();
        unset($data['hydrationPlans']['App\\Dto']['objectRules']);

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_hydration_plan_parameter_missing_its_presence_flag(): void
    {
        $data = $this->validData();
        unset($data['hydrationPlans']['App\\Dto']['parameters'][0]['absent']);

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    public function test_from_array_rejects_a_hydration_plan_whose_object_rules_are_not_descriptors(): void
    {
        $data = $this->validData();
        $data['hydrationPlans']['App\\Dto']['objectRules'] = [['class' => 'App\\Rule']];

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }

    /**
     * The recursive case: a malformed *nested* plan, one level deep,
     * must be caught too — not just the top-level shape.
     */
    public function test_from_array_rejects_a_malformed_nested_hydration_plan(): void
    {
        $data = $this->validData();
        $data['hydrationPlans']['App\\Dto']['parameters'][0]['nestedPlan'] = ['className' => 'App\\Nested'];

        $this->expectException(CacheArtifactExceptionInterface::class);

        HttpCache::fromArray($data);
    }
}
