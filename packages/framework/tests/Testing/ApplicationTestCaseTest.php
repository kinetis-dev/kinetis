<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Config\Config;
use Kinetis\Testing\ApplicationTestCase;
use Kinetis\Tests\Cache\Fixtures\BootstrapMarker;

/**
 * Exercises the base class by extending it, which is the only way it is
 * ever used. The fixture project under tests/Cache/Fixtures is a real
 * project root: a composer.json declaring a PSR-4 map, a bootstrap.php,
 * and a controller for discovery to find.
 */
final class ApplicationTestCaseTest extends ApplicationTestCase
{
    use RecordsHookOrder;

    protected function projectRoot(): string
    {
        return dirname(__DIR__) . '/Cache/Fixtures';
    }

    protected function configOverrides(): array
    {
        return ['APP_ENV' => 'development', 'SOME_SETTING' => 'from-the-test'];
    }

    public function test_the_application_is_booted_before_the_test_body_runs(): void
    {
        self::assertTrue($this->app->isBooted());
        self::assertSame($this->app, $this->application->app);
    }

    public function test_the_client_reaches_a_discovered_route(): void
    {
        $this->client->get('/fixture-ping')->assertOk();
    }

    public function test_bootstrap_php_has_run_against_the_container(): void
    {
        self::assertInstanceOf(BootstrapMarker::class, $this->app->get(BootstrapMarker::class));
    }

    public function test_config_overrides_reach_the_booted_container(): void
    {
        self::assertSame('from-the-test', $this->app->get(Config::class)->string('SOME_SETTING', ''));
    }

    /**
     * The property that makes this class compose with the database
     * isolation traits in kinetis/persistence: those declare their own
     * #[Before] and read $this->app from it, which only works because
     * PHPUnit runs a parent class's hooks before the ones a subclass or
     * its traits add.
     */
    public function test_a_traits_own_before_hook_sees_the_booted_application(): void
    {
        self::assertTrue($this->appWasBootedBeforeTheTraitHookRan);
    }
}
