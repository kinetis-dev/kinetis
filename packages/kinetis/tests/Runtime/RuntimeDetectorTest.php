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
}
