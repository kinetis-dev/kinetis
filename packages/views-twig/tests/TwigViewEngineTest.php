<?php

declare(strict_types=1);

namespace Kinetis\ViewsTwig\Tests;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewNotFoundException;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Views\ViewRuntime;
use Kinetis\Views\Views;
use Kinetis\ViewsTwig\TwigViewEngine;
use PHPUnit\Framework\TestCase;

final class TwigViewEngineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-views-twig-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function test_renders_and_autoescapes_data_with_the_asset_function(): void
    {
        file_put_contents($this->root . '/welcome.twig', 'Hello {{ name }} <link href="{{ asset(\'css/app.css\') }}">');
        $views = new Views(new TwigViewEngine(
            $this->root,
            $this->runtime(AppEnvironment::Development),
            new AssetUrl('/static'),
        ));

        self::assertSame(
            'Hello Ada &amp; Bob <link href="/static/css/app.css">',
            $views->render('welcome', ['name' => 'Ada & Bob']),
        );
    }

    public function test_render_data_does_not_survive_into_the_next_render(): void
    {
        file_put_contents($this->root . '/value.twig', '{{ value|default(\'none\') }}');
        $views = new Views(new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development)));

        self::assertSame('first', $views->render('value', ['value' => 'first']));
        self::assertSame('none', $views->render('value'));
    }

    public function test_can_use_a_compilation_cache_with_refresh_checks_disabled(): void
    {
        file_put_contents($this->root . '/cached.twig', 'cached');
        $engine = new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Production));

        self::assertFalse($engine->engine()->isAutoReload());
        self::assertSame('cached', (new Views($engine))->render('cached'));
        self::assertNotEmpty(glob($this->root . '/.kinetis-cache/views/twig/*') ?: []);
    }

    public function test_warm_scans_nested_twig_templates_and_replaces_stale_cache(): void
    {
        mkdir($this->root . '/nested');
        file_put_contents($this->root . '/z.twig', 'z');
        file_put_contents($this->root . '/nested/a.twig', 'a');
        file_put_contents($this->root . '/ignored.latte', 'ignored');
        symlink($this->root . '/z.twig', $this->root . '/linked.twig');
        $engine = new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Production));
        file_put_contents($this->root . '/.kinetis-cache/views/twig/stale.php', 'stale');

        self::assertSame(2, $engine->warmCache());
        self::assertFileDoesNotExist($this->root . '/.kinetis-cache/views/twig/stale.php');
        self::assertNotEmpty(glob($this->root . '/.kinetis-cache/views/twig/*') ?: []);
    }

    public function test_template_class_is_independent_of_the_process_working_directory(): void
    {
        file_put_contents($this->root . '/cached.twig', 'cached');
        $before = getcwd();
        self::assertIsString($before);

        try {
            chdir($this->root);
            $first = new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development));
            $firstClass = $first->engine()->getTemplateClass('cached.twig');
            chdir(sys_get_temp_dir());
            $second = new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development));

            self::assertSame($firstClass, $second->engine()->getTemplateClass('cached.twig'));
        } finally {
            chdir($before);
        }
    }

    public function test_development_warm_is_a_noop_but_clear_removes_a_stale_production_cache(): void
    {
        $engine = new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development));
        $cache = $this->root . '/.kinetis-cache/views/twig';
        mkdir($cache, recursive: true);
        file_put_contents($cache . '/stale.php', 'stale');

        self::assertTrue($engine->engine()->isAutoReload());
        self::assertSame(0, $engine->warmCache());
        self::assertSame(1, $engine->clearCache());
        self::assertFileDoesNotExist($cache . '/stale.php');
    }

    public function test_missing_view_uses_the_common_exception(): void
    {
        $this->expectException(ViewNotFoundException::class);
        (new Views(new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development))))->render('missing');
    }

    public function test_template_failure_uses_the_common_exception_and_keeps_the_vendor_cause(): void
    {
        file_put_contents($this->root . '/broken.twig', '{% if %}');

        try {
            (new Views(new TwigViewEngine($this->root, $this->runtime(AppEnvironment::Development))))->render('broken');
            self::fail('The invalid template should have failed.');
        } catch (ViewRenderException $exception) {
            self::assertSame("View 'broken' could not be rendered.", $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    private function runtime(AppEnvironment $environment): ViewRuntime
    {
        return new ViewRuntime($this->root, $environment);
    }
}
