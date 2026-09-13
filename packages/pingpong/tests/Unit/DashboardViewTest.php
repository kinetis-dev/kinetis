<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Views;
use Kinetis\ViewsPhp\PhpViewEngine;
use PHPUnit\Framework\TestCase;

final class DashboardViewTest extends TestCase
{
    public function test_the_dashboard_renders_through_views_php_with_public_asset_urls(): void
    {
        $views = new Views(new PhpViewEngine(
            dirname(__DIR__, 2) . '/resources/views',
            new AssetUrl('/'),
        ));

        $html = $views->render('dashboard', ['broadcastConfig' => [
            'key' => 'test-key',
            'host' => 'localhost',
            'port' => 6001,
        ]]);

        self::assertStringContainsString('href="/favicon.svg"', $html);
        self::assertStringContainsString('href="/dashboard.css"', $html);
        self::assertStringContainsString('src="/dashboard.js"', $html);
        self::assertStringContainsString('"key":"test-key"', $html);
    }
}
