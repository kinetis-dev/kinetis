<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Attributes;

use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Tests\Http\Fixtures\AdminScopedMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;
use Kinetis\Tests\Http\Fixtures\VersionedMiddleware;
use PHPUnit\Framework\TestCase;

final class RoutePrefixTest extends TestCase
{
    public function test_join_normalises_stray_slashes_on_either_side(): void
    {
        self::assertSame('/v1/users', new RoutePrefix('/v1')->join('users'));
        self::assertSame('/v1/users', new RoutePrefix('/v1/')->join('/users'));
    }

    public function test_join_puts_a_route_declaring_a_slash_at_the_prefix_itself(): void
    {
        // The trailing "/" this leaves is real — Route itself is what
        // normalises "/v1/" and "/v1" to the same matched route, not
        // join(), which only ever guarantees a separator.
        self::assertSame('/v1/', new RoutePrefix('/v1')->join('/'));
    }

    public function test_declared_on_reads_the_prefix_off_a_class_that_carries_one(): void
    {
        $prefixes = RoutePrefix::declaredOn([VersionedMiddleware::class]);

        self::assertSame('/v1', $prefixes[VersionedMiddleware::class]->prefix);
    }

    public function test_declared_on_skips_a_class_with_no_route_prefix(): void
    {
        self::assertSame([], RoutePrefix::declaredOn([RecordingMiddleware::class]));
    }

    public function test_declared_on_skips_a_group_reference_rather_than_erroring(): void
    {
        self::assertSame([], RoutePrefix::declaredOn(['@some-group']));
    }

    public function test_declared_on_skips_a_nonexistent_class(): void
    {
        self::assertSame([], RoutePrefix::declaredOn(['Kinetis\\Tests\\Http\\Fixtures\\DoesNotExist']));
    }

    public function test_declared_on_preserves_input_order_and_is_keyed_by_class(): void
    {
        $prefixes = RoutePrefix::declaredOn([VersionedMiddleware::class, AdminScopedMiddleware::class]);

        self::assertSame(
            [VersionedMiddleware::class, AdminScopedMiddleware::class],
            array_keys($prefixes),
        );
        self::assertSame('/v1', $prefixes[VersionedMiddleware::class]->prefix);
        self::assertSame('/admin', $prefixes[AdminScopedMiddleware::class]->prefix);
    }

    public function test_join_all_folds_prefixes_outer_to_inner(): void
    {
        $outer = new RoutePrefix('/v1');
        $inner = new RoutePrefix('/admin');

        self::assertSame('/v1/admin/users/{id}', RoutePrefix::joinAll('/users/{id}', $outer, $inner));
    }

    public function test_join_all_with_no_prefixes_returns_the_path_unchanged(): void
    {
        self::assertSame('/users', RoutePrefix::joinAll('/users'));
    }
}
