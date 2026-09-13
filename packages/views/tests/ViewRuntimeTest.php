<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use Kinetis\Config\Config;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Views\ViewRuntime;
use PHPUnit\Framework\TestCase;

final class ViewRuntimeTest extends TestCase
{
    public function test_derives_an_isolated_engine_cache_beside_the_aot_artifact(): void
    {
        $root = sys_get_temp_dir() . '/kinetis-view-runtime-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $runtime = new ViewRuntime($root, AppEnvironment::Production);

            self::assertTrue($runtime->cachesTemplates());
            self::assertSame(
                realpath($root) . '/.kinetis-cache/views/latte',
                $runtime->cacheDirectory('latte')->path(),
            );
        } finally {
            rmdir($root);
        }
    }

    public function test_from_config_disables_template_caching_only_for_development(): void
    {
        self::assertFalse(ViewRuntime::fromConfig(__DIR__, new Config(['APP_ENV' => 'development']))->cachesTemplates());
        self::assertTrue(ViewRuntime::fromConfig(__DIR__, new Config(['APP_ENV' => 'staging']))->cachesTemplates());
    }
}
