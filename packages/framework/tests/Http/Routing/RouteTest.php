<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\InvalidRouteDefinitionException;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;
use Kinetis\Http\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public function test_a_plain_placeholder_matches_any_non_slash_segment(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame(['id' => 'abc'], $route->matchPath('/users/abc'));
        self::assertSame(['id' => '42'], $route->matchPath('/users/42'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function pathsToNormalize(): array
    {
        return [
            ['/users', '/users'],
            ['/users/', '/users'],
            ['users', '/users'],
            ['users/', '/users'],
            ['/', '/'],
            ['', '/'],
            ['/users/{id}/', '/users/{id}'],
        ];
    }

    /**
     * Route normalizes whatever it is handed, including a path with no
     * leading slash — Router rejects that earlier
     * (Exception\InvalidRoutePathException), so in practice this only
     * ever fires for the trailing slash, but Route is also constructed by
     * fromArray() and directly, and stays self-consistent for both.
     */
    #[DataProvider('pathsToNormalize')]
    public function test_a_path_is_stored_in_one_canonical_form(string $declared, string $expected): void
    {
        $route = new Route('GET', $declared, 'C', 'm', 200);

        self::assertSame($expected, $route->pathTemplate);
    }

    /**
     * The request path goes through the same rule as the declared one, so
     * one route answers one set of requests however either was written.
     */
    public function test_a_trailing_slash_on_the_request_path_still_matches(): void
    {
        $route = new Route('GET', '/users', 'C', 'm', 200);

        self::assertNotNull($route->matchPath('/users'));
        self::assertNotNull($route->matchPath('/users/'));
    }

    public function test_the_root_route_still_matches_the_root_path(): void
    {
        $route = new Route('GET', '/', 'C', 'm', 200);

        self::assertNotNull($route->matchPath('/'));
        // A PSR-7 URI may carry no path component at all.
        self::assertNotNull($route->matchPath(''));
    }

    public function test_a_trailing_slash_after_a_path_parameter_still_binds_it(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame(['id' => '7'], $route->matchPath('/users/7/'));
    }

    public function test_paths_differing_only_by_a_trailing_slash_are_the_same_route(): void
    {
        $withSlash = new Route('GET', '/users/', 'C', 'm', 200);
        $without = new Route('GET', '/users', 'C', 'm', 200);

        // Which is why registering both is a duplicate rather than two
        // routes each answering half the requests a caller would expect.
        self::assertSame($without->conflictKey(), $withSlash->conflictKey());
    }

    public function test_a_duplicate_placeholder_name_throws_at_construction(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('declares the placeholder "{id}" more than once');

        new Route('GET', '/users/{id}/orders/{id}', 'C', 'm', 200);
    }

    public function test_an_invalid_http_method_token_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid HTTP method token');

        new Route('get', '/x', 'C', 'm', 200);
    }

    public function test_a_hyphenated_extension_http_method_is_accepted(): void
    {
        $route = new Route('M-SEARCH', '/x', 'C', 'm', 200);

        self::assertSame('M-SEARCH', $route->httpMethod);
    }

    public function test_a_hyphenated_webdav_http_method_is_accepted(): void
    {
        $route = new Route('VERSION-CONTROL', '/x', 'C', 'm', 200);

        self::assertSame('VERSION-CONTROL', $route->httpMethod);
    }

    public function test_an_http_method_containing_a_digit_is_accepted(): void
    {
        $route = new Route('X2', '/x', 'C', 'm', 200);

        self::assertSame('X2', $route->httpMethod);
    }

    public function test_an_http_method_containing_every_extra_tchar_character_is_accepted(): void
    {
        $token = "X!#\$%&'*+.^_`|~Y";
        $route = new Route($token, '/x', 'C', 'm', 200);

        self::assertSame($token, $route->httpMethod);
    }

    public function test_an_http_method_containing_a_forbidden_separator_is_still_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid HTTP method token');

        new Route('GET/POST', '/x', 'C', 'm', 200);
    }

    public function test_a_status_outside_the_valid_http_range_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid HTTP response status');

        new Route('GET', '/x', 'C', 'm', 999);
    }

    public function test_an_invalid_controller_class_shape_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid class-string');

        new Route('GET', '/x', '1Bad', 'm', 200);
    }

    public function test_an_invalid_controller_method_shape_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid method name');

        new Route('GET', '/x', 'C', '1bad', 200);
    }

    public function test_an_invalid_middleware_reference_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid middleware reference');

        new Route('GET', '/x', 'C', 'm', 200, ['1Bad']);
    }

    public function test_a_group_reference_with_an_invalid_shape_is_rejected(): void
    {
        $this->expectException(InvalidRouteDefinitionException::class);
        $this->expectExceptionMessage('is not a valid middleware reference');

        new Route('GET', '/x', 'C', 'm', 200, ['@']);
    }

    public function test_a_path_containing_a_control_character_is_rejected(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('must not contain control characters');

        new Route('GET', "/x/\x00y", 'C', 'm', 200);
    }

    public function test_compare_for_matching_ranks_a_static_segment_ahead_of_a_placeholder(): void
    {
        $static = new Route('GET', '/users/self', 'C', 'a', 200);
        $placeholder = new Route('GET', '/users/{id}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($static, $placeholder));
        self::assertGreaterThan(0, Route::compareForMatching($placeholder, $static));
    }

    public function test_compare_for_matching_ranks_more_segments_ahead_on_a_tie(): void
    {
        $deeper = new Route('GET', '/users/{id}/edit', 'C', 'a', 200);
        $shallower = new Route('GET', '/users/{id}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($deeper, $shallower));
    }

    public function test_compare_for_matching_falls_back_to_content_when_every_segment_ties(): void
    {
        $deleteRoute = new Route('DELETE', '/users/{id}', 'C', 'a', 200);
        $getRoute = new Route('GET', '/users/{id}', 'C', 'a', 200);

        // 'DELETE' sorts before 'GET' alphabetically.
        self::assertLessThan(0, Route::compareForMatching($deleteRoute, $getRoute));
    }

    public function test_compare_for_matching_treats_an_identical_route_as_equal(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'a', 200);

        self::assertSame(0, Route::compareForMatching($route, $route));
    }

    public function test_compare_for_matching_ranks_a_fully_literal_segment_ahead_of_a_mixed_segment(): void
    {
        $literal = new Route('GET', '/files/report-2026.pdf', 'C', 'a', 200);
        $mixed = new Route('GET', '/files/report-{id}.pdf', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($literal, $mixed));
        self::assertGreaterThan(0, Route::compareForMatching($mixed, $literal));
    }

    public function test_compare_for_matching_ranks_a_mixed_segment_ahead_of_a_pure_placeholder(): void
    {
        $mixed = new Route('GET', '/files/report-{id}.pdf', 'C', 'a', 200);
        $pure = new Route('GET', '/files/{id}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($mixed, $pure));
    }

    public function test_a_brace_expression_carrying_an_inline_pattern_is_rejected(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('which is not a placeholder');

        new Route('GET', '/products/{id:\\d+}', 'C', 'm', 200);
    }

    public function test_a_brace_expression_that_is_not_a_placeholder_name_is_rejected_rather_than_read_as_literal_text(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('which is not a placeholder');

        new Route('GET', '/products/{not a name}', 'C', 'm', 200);
    }

    public function test_an_unterminated_brace_expression_is_rejected(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('which is not a placeholder');

        new Route('GET', '/products/{id', 'C', 'm', 200);
    }

    public function test_two_adjacent_placeholders_in_one_segment_are_rejected(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('two placeholders with nothing between them');

        new Route('GET', '/files/{first}{second}', 'C', 'm', 200);
    }

    public function test_placeholders_separated_by_literal_text_in_one_segment_stay_valid(): void
    {
        $route = new Route('GET', '/files/{name}-{version}.pdf', 'C', 'm', 200);

        self::assertSame(['name' => 'report', 'version' => '3'], $route->matchPath('/files/report-3.pdf'));
    }

    public function test_two_routes_differing_only_in_placeholder_name_claim_the_same_requests(): void
    {
        $byId = new Route('GET', '/users/{id}', 'C', 'a', 200);
        $byUserId = new Route('GET', '/users/{userId}', 'C', 'b', 200);

        self::assertSame($byId->conflictKey(), $byUserId->conflictKey());
    }
}
