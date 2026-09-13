<?php

declare(strict_types=1);

namespace Kinetis\ViewsPhp\Tests;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewNotFoundException;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\Views;
use Kinetis\ViewsPhp\PhpViewEngine;
use PHPUnit\Framework\TestCase;

final class PhpViewEngineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-views-php-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function test_renders_php_data_and_the_asset_helper(): void
    {
        file_put_contents($this->root . '/welcome.php', 'Hello <?= htmlspecialchars($name) ?> <link href="<?= $asset(\'css/app.css\') ?>">');
        $views = new Views(new PhpViewEngine($this->root, new AssetUrl('/static')));

        self::assertSame(
            'Hello Ada &amp; Bob <link href="/static/css/app.css">',
            $views->render('welcome', ['name' => 'Ada & Bob']),
        );
    }

    public function test_render_data_does_not_survive_into_the_next_render(): void
    {
        file_put_contents($this->root . '/value.php', '<?= $value ?? "none" ?>');
        $views = new Views(new PhpViewEngine($this->root));

        self::assertSame('first', $views->render('value', ['value' => 'first']));
        self::assertSame('none', $views->render('value'));
    }

    public function test_all_non_reserved_data_keys_reach_the_template(): void
    {
        file_put_contents($this->root . '/keys.php', '<?= $path ?>:<?= $data ?>:<?= $__kinetisTemplate ?>');
        $views = new Views(new PhpViewEngine($this->root));

        self::assertSame('one:two:three', $views->render('keys', [
            'path' => 'one',
            'data' => 'two',
            '__kinetisTemplate' => 'three',
        ]));
    }

    public function test_restores_all_template_buffers_when_rendering_throws(): void
    {
        file_put_contents($this->root . '/broken.php', '<?php ob_start(); echo "partial"; throw new RuntimeException("secret");');
        $views = new Views(new PhpViewEngine($this->root));
        $level = ob_get_level();

        try {
            $views->render('broken');
            self::fail('The render should have failed.');
        } catch (ViewRenderException $exception) {
            self::assertSame("View 'broken' could not be rendered.", $exception->getMessage());
            self::assertSame('secret', $exception->getPrevious()?->getMessage());
            self::assertSame($level, ob_get_level());
        }
    }

    public function test_rejects_a_template_that_leaves_an_extra_buffer_open(): void
    {
        file_put_contents($this->root . '/buffer.php', '<?php ob_start(); echo "nested";');
        $views = new Views(new PhpViewEngine($this->root));
        $level = ob_get_level();

        try {
            $views->render('buffer');
            self::fail('The render should have failed.');
        } catch (ViewRenderException $exception) {
            self::assertSame('The template changed the rendering output-buffer depth.', $exception->getPrevious()?->getMessage());
            self::assertSame($level, ob_get_level());
        }
    }

    public function test_missing_view_uses_the_common_exception(): void
    {
        $this->expectException(ViewNotFoundException::class);
        (new Views(new PhpViewEngine($this->root)))->render('missing');
    }
}
