<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use InvalidArgumentException;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Kinetis\Views\Views;
use PHPUnit\Framework\TestCase;

final class ViewsTest extends TestCase
{
    public function test_render_passes_a_validated_name_and_fresh_data_to_the_engine(): void
    {
        $engine = new class implements ViewEngineInterface {
            public function render(ViewName $view, array $data): string
            {
                return $view->value . ':' . $data['name'];
            }

            public function warmCache(): int
            {
                return 2;
            }

            public function clearCache(): int
            {
                return 3;
            }
        };

        $views = new Views($engine);

        self::assertSame('articles/index:Ada', $views->render('articles/index', ['name' => 'Ada']));
        self::assertSame(2, $views->warmCache());
        self::assertSame(3, $views->clearCache());
    }

    public function test_response_is_html_with_the_requested_status(): void
    {
        $engine = new class implements ViewEngineInterface {
            public function render(ViewName $view, array $data): string
            {
                return '<h1>Hello</h1>';
            }

            public function warmCache(): int
            {
                return 0;
            }

            public function clearCache(): int
            {
                return 0;
            }
        };

        $response = (new Views($engine))->response('hello', status: 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function test_rejects_traversal_absolute_extensions_and_empty_segments(): void
    {
        $rejected = 0;

        foreach (['../secret', '/absolute', 'index.php', 'articles//index', 'articles/../index'] as $name) {
            try {
                new ViewName($name);
                self::fail("{$name} should have been rejected.");
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(5, $rejected);
    }

    public function test_accepts_nested_unicode_logical_names(): void
    {
        self::assertSame('artikelen/over-ons', (new ViewName('artikelen/over-ons'))->value);
        self::assertSame('artikelen/über-uns', (new ViewName('artikelen/über-uns'))->value);
    }

    public function test_rejects_non_string_data_keys_and_the_reserved_asset_name(): void
    {
        $views = new Views(new class implements ViewEngineInterface {
            public function render(ViewName $view, array $data): string
            {
                return '';
            }

            public function warmCache(): int
            {
                return 0;
            }

            public function clearCache(): int
            {
                return 0;
            }
        });

        $rejected = 0;

        foreach ([[0 => 'value'], ['not-a-variable' => 'value'], ['asset' => 'shadow'], ['GLOBALS' => []]] as $data) {
            try {
                $views->render('index', $data);
                self::fail('Invalid view data should have been rejected.');
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }
}
