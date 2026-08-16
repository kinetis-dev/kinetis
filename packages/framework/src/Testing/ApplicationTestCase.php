<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Kinetis\Container\AppScope;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * A PHPUnit base class that boots the real application once per test and
 * exposes a client against it.
 *
 *     final class OrderControllerTest extends ApplicationTestCase
 *     {
 *         protected function projectRoot(): string
 *         {
 *             return dirname(__DIR__);
 *         }
 *
 *         public function test_it_lists_orders(): void
 *         {
 *             $this->client->get('/orders')->assertOk()->assertJsonPath('0.sku', 'A1');
 *         }
 *     }
 *
 * The work happens in {@see TestApplication}, which has no PHPUnit
 * dependency — this class is the wiring, so a suite that wants a
 * different lifecycle (one application shared across a whole test class,
 * a different runner) can use that directly instead of inheriting.
 *
 * A fresh application per test keeps container state from leaking between
 * them, matching the per-request isolation the framework itself gives.
 * Discovery is the cost, not the container: override
 * {@see configOverrides()} rather than avoiding the boot.
 */
abstract class ApplicationTestCase extends TestCase
{
    protected TestApplication $application;

    protected TestClient $client;

    protected AppScope $app;

    /**
     * The directory holding the application's composer.json — what
     * discovery scans and where bootstrap.php is looked for.
     */
    abstract protected function projectRoot(): string;

    /**
     * Configuration applied over the real environment and .env — a test
     * database name, a queue pointed at a fake, anything the suite needs
     * to differ from a development run.
     *
     * @return array<string, string>
     */
    protected function configOverrides(): array
    {
        return [];
    }

    #[Before]
    protected function bootApplication(): void
    {
        $this->application = TestApplication::boot($this->projectRoot(), $this->configOverrides());
        $this->client = $this->application->client();
        $this->app = $this->application->app;
    }
}
