<?php

declare(strict_types=1);

namespace Kinetis\ViewsLatte\Tests;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewNotFoundException;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\Views;
use Kinetis\ViewsLatte\LatteViewEngine;
use PHPUnit\Framework\TestCase;

final class LatteViewEngineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-views-latte-' . bin2hex(random_bytes(8));
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
        file_put_contents($this->root . '/welcome.latte', 'Hello {$name} <link href="{asset(\'css/app.css\')}">');
        $views = new Views(new LatteViewEngine($this->root, new AssetUrl('/static')));

        self::assertSame(
            'Hello Ada &amp; Bob <link href="/static/css/app.css">',
            $views->render('welcome', ['name' => 'Ada & Bob']),
        );
    }

    public function test_render_data_does_not_survive_into_the_next_render(): void
    {
        file_put_contents($this->root . '/value.latte', '{$value ?? \'none\'}');
        $views = new Views(new LatteViewEngine($this->root));

        self::assertSame('first', $views->render('value', ['value' => 'first']));
        self::assertSame('none', $views->render('value'));
    }

    public function test_can_use_a_compilation_cache_and_disable_refresh_checks(): void
    {
        $cache = $this->root . '/cache';
        mkdir($cache);
        file_put_contents($this->root . '/cached.latte', 'cached');
        $engine = new LatteViewEngine($this->root, cacheDirectory: $cache, autoRefresh: false);

        self::assertSame('cached', (new Views($engine))->render('cached'));
        self::assertNotEmpty(glob($cache . '/*') ?: []);
    }

    public function test_missing_view_uses_the_common_exception(): void
    {
        $this->expectException(ViewNotFoundException::class);
        (new Views(new LatteViewEngine($this->root)))->render('missing');
    }

    public function test_template_failure_uses_the_common_exception_and_keeps_the_vendor_cause(): void
    {
        file_put_contents($this->root . '/broken.latte', '{if}');

        try {
            (new Views(new LatteViewEngine($this->root)))->render('broken');
            self::fail('The invalid template should have failed.');
        } catch (ViewRenderException $exception) {
            self::assertSame("View 'broken' could not be rendered.", $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }
}
