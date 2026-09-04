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

    public function test_defaults_to_production_when_app_env_is_unset(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect(null));
        self::assertTrue(AppEnvironment::detect(null)->isProduction());
    }

    /**
     * Only the exact name `development` selects Development; every other
     * value selects Production — `production` itself along with a
     * deployment's own `staging` and any abbreviation of either name. A
     * typo or an unfamiliar name therefore lands on the safer side of the
     * gate rather than turning on development behavior in a deployed
     * process.
     */
    public function test_defaults_to_production_for_any_other_name(): void
    {
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('staging'));
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('dev'));
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('prod'));
        self::assertSame(AppEnvironment::Production, AppEnvironment::detect('developmentx'));
    }
}
