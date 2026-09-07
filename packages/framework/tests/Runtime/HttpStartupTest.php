<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use FilesystemIterator;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Runtime\Adapters\FpmAdapter;
use Kinetis\Runtime\HttpStartup;
use Kinetis\Tests\Instrumentation\RecordingTelemetry;
use Kinetis\Tests\Runtime\Fixtures\RecordingAdapter;
use Kinetis\Tests\Runtime\Fixtures\StartupProject\Events\StartupEvent;
use Kinetis\Tests\Runtime\Fixtures\StartupProject\Events\StartupListener;
use Kinetis\Tests\Runtime\Fixtures\StartupProject\Http\StartupPingController;
use Kinetis\Tests\Runtime\Fixtures\StartupProject\Http\StartupStampMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// The same test-only gc_collect_cycles() override KernelTest uses, so
// the persistent flag the adapter reports can be observed on the Kernel
// startup built from it.
require_once dirname(__DIR__) . '/Http/Fixtures/gc_collect_cycles_spy.php';

/**
 * `HttpStartup` is the whole HTTP boot program every application's
 * `public/index.php` runs, so these drive the real thing against a real
 * project root: real discovery, a real published artifact, the real
 * `bootstrap.php` chain and a real `Kernel` answering a real request.
 *
 * The project is copied to a temporary directory per test because a
 * production boot publishes `.kinetis-cache/compiled.php` into it. The
 * copy carries the fixture's own `composer.json`, so discovery derives
 * the same class names from it and the ordinary autoloader resolves them
 * from the repository — {@see \Kinetis\Cache\NamespaceScanner} only ever
 * derives names from a PSR-4 layout, it never includes a class file
 * itself.
 *
 * `Fixtures\RecordingAdapter` stands in for the detected runtime adapter
 * wherever a test needs to see what reached it, since a real adapter's
 * `run()` is a request loop that does not return.
 */
final class HttpStartupTest extends TestCase
{
    private const string SOURCE_PROJECT = __DIR__ . '/Fixtures/StartupProject';

    private string $projectRoot;

    private string|false $appEnv;

    protected function setUp(): void
    {
        $this->appEnv = getenv('APP_ENV');
        $this->projectRoot = sys_get_temp_dir() . '/kinetis_http_startup_' . bin2hex(random_bytes(8));

        self::copyDirectory(self::SOURCE_PROJECT, $this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->appEnv === false ? putenv('APP_ENV') : putenv("APP_ENV={$this->appEnv}");
        Telemetry::global()->swap(new NullTelemetry());
        self::removeDirectory($this->projectRoot);
    }

    // --- Which registrations a boot ends up with ---

    public function test_a_development_boot_discovers_routes_middleware_and_listeners_live(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));
        $response = $started->kernel->handle(new ServerRequest('GET', '/startup-ping'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"pong"', (string) $response->getBody());
        self::assertSame('ran', $response->getHeaderLine(StartupStampMiddleware::HEADER));

        /** @var EventListenerRegistry $listeners */
        $listeners = $started->app->get(EventListenerRegistry::class);
        self::assertSame(StartupListener::class, $listeners->listenersFor(StartupEvent::class)[0]['class']);

        self::assertFileDoesNotExist($this->artifactPath());
    }

    public function test_the_application_bootstrap_replaces_the_runtime_policies_startup_registered(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        /** @var TrustedProxies $proxies */
        $proxies = $started->app->get(TrustedProxies::class);
        /** @var FormLimits $limits */
        $limits = $started->app->get(FormLimits::class);

        // Both defaults come from a Config with neither key set, which
        // trusts nobody and allows the built-in body ceiling — so these
        // values can only be the bootstrap's own.
        self::assertTrue($proxies->trusts('10.1.2.3'));
        self::assertSame(4_096, $limits->maxBodyBytes);
    }

    public function test_the_container_is_locked_by_the_time_startup_returns(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertTrue($started->app->isBooted());

        $this->expectException(ContainerException::class);
        $started->app->instance(TrustedProxies::class, TrustedProxies::fromList([]));
    }

    // --- The AOT artifact ---

    public function test_a_production_boot_with_no_artifact_compiles_once_and_publishes_it(): void
    {
        putenv('APP_ENV=production');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertFileExists($this->artifactPath());
        self::assertSame(200, $started->kernel->handle(new ServerRequest('GET', '/startup-ping'))->getStatusCode());

        $published = new CacheStore($this->projectRoot . '/.kinetis-cache')->load();
        self::assertNotNull($published);
        self::assertContains('/startup-ping', array_column($published->http->routes, 'pathTemplate'));
    }

    public function test_a_production_boot_serves_what_the_published_artifact_holds(): void
    {
        putenv('APP_ENV=production');
        $this->publish('/from-the-artifact');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        $response = $started->kernel->handle(new ServerRequest('GET', '/from-the-artifact'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ran', $response->getHeaderLine(StartupStampMiddleware::HEADER));
        // The path the source declares is not in the artifact, so a boot
        // that had recompiled instead of reading it would answer this.
        self::assertSame(404, $started->kernel->handle(new ServerRequest('GET', '/startup-ping'))->getStatusCode());
    }

    public function test_a_production_boot_recompiles_and_republishes_an_unusable_artifact(): void
    {
        putenv('APP_ENV=production');
        $this->publish('/from-the-artifact');
        file_put_contents($this->artifactPath(), "<?php\n\nreturn 'not an artifact at all';\n");

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertSame(200, $started->kernel->handle(new ServerRequest('GET', '/startup-ping'))->getStatusCode());

        $republished = new CacheStore($this->projectRoot . '/.kinetis-cache')->load();
        self::assertNotNull($republished);
        self::assertContains('/startup-ping', array_column($republished->http->routes, 'pathTemplate'));
    }

    // --- What reaches the runtime adapter and the Kernel ---

    public function test_the_adapter_is_handed_the_proxy_policy_the_booted_container_holds(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertInstanceOf(RecordingAdapter::class, $started->adapter);
        self::assertSame($started->app->get(TrustedProxies::class), $started->adapter->trustedProxies);
    }

    public function test_the_kernel_collects_cycles_between_requests_when_the_adapter_is_persistent(): void
    {
        putenv('APP_ENV=development');
        $GLOBALS['kinetisGcCollectCyclesCallCount'] = 0;

        HttpStartup::assemble($this->projectRoot, fn (TrustedProxies $proxies): RecordingAdapter => new RecordingAdapter($proxies, persistent: true))
            ->kernel
            ->handle(new ServerRequest('GET', '/startup-ping'));

        self::assertSame(1, $GLOBALS['kinetisGcCollectCyclesCallCount']);
    }

    public function test_the_kernel_leaves_cycles_alone_when_the_adapter_is_not_persistent(): void
    {
        putenv('APP_ENV=development');
        $GLOBALS['kinetisGcCollectCyclesCallCount'] = 0;

        HttpStartup::assemble($this->projectRoot, $this->adapter(...))
            ->kernel
            ->handle(new ServerRequest('GET', '/startup-ping'));

        self::assertSame(0, $GLOBALS['kinetisGcCollectCyclesCallCount']);
    }

    public function test_startup_detects_the_runtime_this_process_is_actually_running_in(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot);

        // No FrankenPHP function, no RR_MODE, no Lambda runtime API under
        // a plain CLI process — the boot-and-die adapter is the honest
        // answer, and nothing about it was passed in.
        self::assertInstanceOf(FpmAdapter::class, $started->adapter);
    }

    public function test_serving_hands_the_kernel_to_the_adapters_own_loop(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));
        $started->serve();

        self::assertInstanceOf(RecordingAdapter::class, $started->adapter);
        self::assertNotNull($started->adapter->handler);
        self::assertSame(200, ($started->adapter->handler)(new ServerRequest('GET', '/startup-ping'))->getStatusCode());
    }

    // --- Telemetry ---

    public function test_the_startup_phases_reach_a_backend_the_bootstrap_chain_installed(): void
    {
        putenv('APP_ENV=development');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertSame(
            ['bootstrap.env', 'bootstrap.discovery', 'bootstrap.services'],
            $this->reportedPhases($started->app->get(RecordingTelemetry::class)),
        );
    }

    public function test_a_production_boot_reports_no_discovery_phase(): void
    {
        putenv('APP_ENV=production');

        $started = HttpStartup::assemble($this->projectRoot, $this->adapter(...));

        self::assertSame(
            ['bootstrap.env', 'bootstrap.services'],
            $this->reportedPhases($started->app->get(RecordingTelemetry::class)),
        );
    }

    // --- Helpers ---

    private function adapter(TrustedProxies $proxies): RecordingAdapter
    {
        return new RecordingAdapter($proxies);
    }

    private function artifactPath(): string
    {
        return $this->projectRoot . '/.kinetis-cache/' . CacheStore::ARTIFACT_FILENAME;
    }

    /**
     * Publishes an artifact whose one route is a path the project's own
     * source never declares, so what a boot serves says which of the two
     * it read.
     */
    private function publish(string $pathTemplate): void
    {
        new CacheStore($this->projectRoot . '/.kinetis-cache')->write(new CompiledCache(
            new HttpCache(
                routes: [[
                    'httpMethod' => 'GET',
                    'pathTemplate' => $pathTemplate,
                    'controllerClass' => StartupPingController::class,
                    'controllerMethod' => 'ping',
                    'status' => 200,
                    'middleware' => [],
                ]],
                httpBindingPlans: [],
                hydrationPlans: [],
                globalMiddleware: [StartupStampMiddleware::class],
                openApiMiddleware: [],
            ),
            new CommandCache([]),
            new EventCache([]),
            new PluginCache([]),
            [],
        ));
    }

    /**
     * @return list<string>
     */
    private function reportedPhases(mixed $telemetry): array
    {
        self::assertInstanceOf(RecordingTelemetry::class, $telemetry);

        $phases = [];

        foreach ($telemetry->calls as [$hook, $arguments]) {
            if ($hook === 'phase' && is_string($arguments[0])) {
                $phases[] = $arguments[0];
            }
        }

        return $phases;
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0o777, true);

        /** @var iterable<string, \SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            $target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);

            $entry->isDir() ? mkdir($target, 0o777, true) : copy($entry->getPathname(), $target);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var iterable<string, \SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
