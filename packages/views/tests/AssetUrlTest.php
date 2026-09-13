<?php

declare(strict_types=1);

namespace Kinetis\Views\Tests;

use InvalidArgumentException;
use Kinetis\Views\AssetUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
    public function test_builds_root_relative_prefixed_and_https_asset_urls(): void
    {
        self::assertSame('/css/app.css', (new AssetUrl())('css/app.css'));
        self::assertSame('/static/js/app.js', (new AssetUrl('/static'))('js/app.js'));
        self::assertSame(
            'https://cdn.example.com/build/app.js',
            (new AssetUrl('https://cdn.example.com/build/'))('app.js'),
        );
    }

    #[DataProvider('invalidBases')]
    public function test_rejects_unsafe_or_ambiguous_bases(string $base): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetUrl($base);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBases(): iterable
    {
        yield 'relative' => ['assets'];
        yield 'http' => ['http://cdn.example.com'];
        yield 'scheme relative' => ['//cdn.example.com'];
        yield 'query' => ['https://cdn.example.com/?v=1'];
        yield 'credentials' => ['https://user:pass@cdn.example.com'];
        yield 'root traversal' => ['/assets/../private'];
        yield 'cdn traversal' => ['https://cdn.example.com/assets/../private'];
    }

    #[DataProvider('invalidPaths')]
    public function test_rejects_non_relative_traversing_or_decorated_paths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssetUrl())($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/app.css'];
        yield 'empty segment' => ['css//app.css'];
        yield 'traversal' => ['css/../secret'];
        yield 'query' => ['app.css?v=1'];
        yield 'fragment' => ['icons.svg#logo'];
        yield 'backslash' => ['css\\app.css'];
        yield 'whitespace' => ['css/app file.css'];
        yield 'html metacharacter' => ['icons/"logo".svg'];
    }
}
