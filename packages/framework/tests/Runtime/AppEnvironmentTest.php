<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Runtime\AppEnvironment;
use PHPUnit\Framework\TestCase;

final class AppEnvironmentTest extends TestCase
{
    public function test_detects_production_from_app_env(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('production'));
        self::assertTrue(AppEnvironment::detect('production')->isProduction());
    }

    public function test_accepts_prod_as_an_alias_for_production(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('prod'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('PRODUCTION'));
        self::assertSame(AppEnvironment::Development, AppEnvironment::detect('DEVELOPMENT'));
    }

    public function test_detects_development_from_app_env(): void
    {
        self::assertSame(AppEnvironment::Development, AppEnvironment::detect('development'));
        self::assertFalse(AppEnvironment::detect('development')->isProduction());
    }

    public function test_accepts_dev_as_an_alias_for_development(): void
    {
        self::assertSame(AppEnvironment::Development, AppEnvironment::detect('dev'));
    }

    public function test_defaults_to_production_when_app_env_is_unset(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect(null));
        self::assertTrue(AppEnvironment::detect(null)->isProduction());
    }

    public function test_defaults_to_production_for_an_unrecognized_value(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('staging'));
    }
}
