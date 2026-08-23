<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Attributes;

use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Patch;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Put;
use Kinetis\Http\Attributes\RouteAttribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every verb, through the one interface Router matches on. Router uses
 * `ReflectionAttribute::IS_INSTANCEOF` against RouteAttribute, so a verb
 * whose class stops implementing it — or reports the wrong method name —
 * silently stops routing rather than failing loudly.
 */
final class RouteAttributeTest extends TestCase
{
    /**
     * Every verb defaults to 200, including POST — a route that creates
     * something declares `status: 201` itself rather than the attribute
     * guessing at intent.
     *
     * @return list<array{class-string<RouteAttribute>, string, int}>
     */
    public static function verbs(): array
    {
        return [
            [Get::class, 'GET', 200],
            [Post::class, 'POST', 200],
            [Put::class, 'PUT', 200],
            [Patch::class, 'PATCH', 200],
            [Delete::class, 'DELETE', 200],
        ];
    }

    /**
     * @param class-string<RouteAttribute> $class
     */
    #[DataProvider('verbs')]
    public function test_reports_its_verb_path_and_default_status(string $class, string $method, int $defaultStatus): void
    {
        $attribute = new $class('/orders');

        self::assertInstanceOf(RouteAttribute::class, $attribute);
        self::assertSame($method, $attribute->httpMethod());
        self::assertSame('/orders', $attribute->path());
        self::assertSame($defaultStatus, $attribute->status());
    }

    /**
     * The verb classes alone, for the cases that need nothing else. A
     * provider may not hand a test more arguments than it declares, so
     * this is derived from verbs() rather than repeated.
     *
     * @return list<array{class-string<RouteAttribute>}>
     */
    public static function verbClasses(): array
    {
        return array_map(static fn (array $verb): array => [$verb[0]], self::verbs());
    }

    /**
     * @param class-string<RouteAttribute> $class
     */
    #[DataProvider('verbClasses')]
    public function test_the_status_can_be_overridden(string $class): void
    {
        self::assertSame(418, new $class('/teapot', status: 418)->status());
    }

    /**
     * @param class-string<RouteAttribute> $class
     */
    #[DataProvider('verbClasses')]
    public function test_is_declared_for_methods_only(string $class): void
    {
        $attributes = new ReflectionClass($class)->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);
        self::assertSame(\Attribute::TARGET_METHOD, $attributes[0]->getArguments()[0]);
    }
}
