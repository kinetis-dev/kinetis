<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Runtime\Adapters\FpmAdapter;
use Kinetis\Runtime\Adapters\FrankenPhpAdapter;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Kinetis\Runtime\RuntimeDetector;
use PHPUnit\Framework\TestCase;

final class RuntimeDetectorTest extends TestCase
{
    public function test_prefers_franken_php_when_available(): void
    {
        $adapter = RuntimeDetector::detect(frankenPhpAvailable: true, lambdaRuntimeApi: '127.0.0.1:9001');

        self::assertInstanceOf(FrankenPhpAdapter::class, $adapter);
    }

    /**
     * BrefLambdaAdapter lives in the separate kinetis/bref-adapter package,
     * not core, and this repo's own test suite never installs it — so this
     * exercises the actual failure mode a consumer hits running under Lambda
     * without that package, rather than the adapter itself. The satellite
     * package's own test suite is what proves detect() actually returns a
     * working BrefLambdaAdapter once installed.
     */
    public function test_throws_a_clear_error_naming_the_adapter_package_when_lambda_is_detected_but_not_installed(): void
    {
        $this->expectException(RuntimeUnavailableException::class);
        $this->expectExceptionMessage('kinetis/bref-adapter');

        RuntimeDetector::detect(frankenPhpAvailable: false, lambdaRuntimeApi: '127.0.0.1:9001');
    }

    public function test_falls_back_to_fpm_when_nothing_else_matches(): void
    {
        $adapter = RuntimeDetector::detect(frankenPhpAvailable: false, lambdaRuntimeApi: null);

        self::assertInstanceOf(FpmAdapter::class, $adapter);
    }

    /**
     * RoadRunnerAdapter lives in the separate kinetis/roadrunner-adapter
     * package, not core, and this repo's own test suite never installs
     * it — so this exercises the actual failure mode a consumer hits
     * running under RoadRunner without that package, the same reasoning
     * as the Lambda test above.
     */
    public function test_throws_a_clear_error_naming_the_adapter_package_when_road_runner_is_detected_but_not_installed(): void
    {
        $this->expectException(RuntimeUnavailableException::class);
        $this->expectExceptionMessage('kinetis/roadrunner-adapter');

        RuntimeDetector::detect(frankenPhpAvailable: false, roadRunnerMode: 'http');
    }

    /**
     * RR_MODE has several real values beyond "http" (temporal, jobs,
     * grpc, tcp, centrifuge, ...) — this adapter must not react to any
     * of them, since it only ever speaks the HTTP worker protocol.
     */
    public function test_a_non_http_road_runner_mode_does_not_select_the_road_runner_adapter(): void
    {
        $adapter = RuntimeDetector::detect(frankenPhpAvailable: false, roadRunnerMode: 'jobs');

        self::assertInstanceOf(FpmAdapter::class, $adapter);
    }
}
