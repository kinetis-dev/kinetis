<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\InvalidRouteDefinitionException;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;
use Kinetis\Http\Routing\Exception\RouteMatchingException;
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

    public function test_a_constraint_admits_matching_text_and_rejects_the_rest(): void
    {
        $route = new Route('GET', '/articles/{slug}', 'C', 'm', 200, where: ['slug' => '[A-Za-z]+']);

        self::assertSame(['slug' => 'Kinetis'], $route->matchPath('/articles/Kinetis'));
        self::assertNull($route->matchPath('/articles/kinetis-2'));
        self::assertNull($route->matchPath('/articles/123'));
    }

    public function test_an_unconstrained_placeholder_still_stops_at_a_slash(): void
    {
        $route = new Route('GET', '/files/{name}', 'C', 'm', 200);

        self::assertSame(['name' => 'a.txt'], $route->matchPath('/files/a.txt'));
        self::assertNull($route->matchPath('/files/a/b'));
    }

    public function test_a_catch_all_constraint_captures_a_tail_containing_slashes(): void
    {
        $route = new Route('GET', '/files/{path}', 'C', 'm', 200, where: ['path' => '.*']);

        self::assertSame(['path' => 'a/b'], $route->matchPath('/files/a/b'));
        self::assertSame(['path' => '../x//y'], $route->matchPath('/files/../x//y'));
    }

    /**
     * `/files/` normalizes to `/files`, which carries no tail at all, so
     * the base path is a separate route rather than an empty capture.
     */
    public function test_a_catch_all_does_not_match_the_base_path(): void
    {
        $route = new Route('GET', '/files/{path}', 'C', 'm', 200, where: ['path' => '.*']);

        self::assertNull($route->matchPath('/files/'));
        self::assertNull($route->matchPath('/files'));
    }

    /**
     * Anchored with `\z`, not `$`: a final newline is text the route does
     * not admit, constrained or not.
     */
    public function test_a_final_newline_is_not_admitted(): void
    {
        $static = new Route('GET', '/users', 'C', 'm', 200);
        $constrained = new Route('GET', '/articles/{slug}', 'C', 'm', 200, where: ['slug' => '[a-z]+']);

        self::assertNull($static->matchPath("/users\n"));
        self::assertNull($constrained->matchPath("/articles/abc\n"));
    }

    /**
     * Alternation in a fragment stays inside the placeholder's capture;
     * it cannot detach the route's literals or anchors.
     */
    public function test_alternation_in_a_fragment_stays_inside_its_placeholder(): void
    {
        $route = new Route('GET', '/v/{kind}/x', 'C', 'm', 200, where: ['kind' => 'a|b']);

        self::assertSame(['kind' => 'b'], $route->matchPath('/v/b/x'));
        self::assertNull($route->matchPath('/v/a'));
        self::assertNull($route->matchPath('/v/c/b/x'));
    }

    /**
     * Characters a printable delimiter would have to escape stay literal
     * fragment syntax, including inside `\Q...\E` and a character class.
     */
    public function test_a_fragment_is_embedded_verbatim(): void
    {
        $quoted = new Route('GET', '/q/{v}', 'C', 'm', 200, where: ['v' => '\Q~#/+\E']);
        $class = new Route('GET', '/c/{v}', 'C', 'm', 200, where: ['v' => '[~#]+']);

        self::assertSame(['v' => '~#/+'], $quoted->matchPath('/q/~#/+'));
        self::assertNull($quoted->matchPath('/q/~#//'));
        self::assertSame(['v' => '~#~'], $class->matchPath('/c/~#~'));
        self::assertNull($class->matchPath('/c/x'));
    }

    public function test_an_escaped_control_sequence_is_ordinary_regex_syntax(): void
    {
        $route = new Route('GET', '/t/{v}', 'C', 'm', 200, where: ['v' => '[^\n]+']);

        self::assertSame(['v' => 'abc'], $route->matchPath('/t/abc'));
    }

    public function test_constraints_are_stored_in_placeholder_order(): void
    {
        $route = new Route('GET', '/{year}/{month}/{slug}', 'C', 'm', 200, where: [
            'slug' => '[a-z-]+',
            'year' => '\d{4}',
        ]);

        self::assertSame(['year' => '\d{4}', 'slug' => '[a-z-]+'], $route->where);
    }

    public function test_an_unconstrained_route_has_an_empty_constraint_map(): void
    {
        self::assertSame([], new Route('GET', '/users/{id}', 'C', 'm', 200)->where);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidConstraintMaps(): iterable
    {
        yield 'a list instead of a map' => [['[a-z]+'], 'invalid where: entry at key 0'];
        yield 'an unknown placeholder' => [['slug' => '[a-z]+'], 'constraint for "{slug}", which is not a placeholder'];
        yield 'an empty fragment' => [['id' => ''], 'invalid where: entry at key "id"'];
        yield 'a non-string fragment' => [['id' => 5], 'invalid where: entry at key "id"'];
        yield 'a literal newline' => [['id' => "a\nb"], 'invalid where: entry at key "id"'];
        yield 'the delimiter byte' => [['id' => "a\x01b"], 'invalid where: entry at key "id"'];
        yield 'an uncompilable fragment' => [['id' => '[z-a]'], 'constraint "[z-a]" for "{id}", which does not compile as a self-contained PCRE2 fragment'];
    }

    /**
     * @param array<array-key, mixed> $where
     */
    #[DataProvider('invalidConstraintMaps')]
    public function test_an_invalid_constraint_map_is_rejected_at_construction(array $where, string $message): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage($message);

        new Route('GET', '/users/{id}', 'C', 'm', 200, where: $where);
    }

    /**
     * `a))|((` compiles inside the finished route by closing the
     * placeholder's groups and reopening replacements, leaving `/tail`
     * and `\z` in another alternative; `(*ACCEPT)` ends the match before
     * `/tail` is tested. Both would admit `/v/a`. Every case fails the
     * fragment's own check, before the route is assembled.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function fragmentsReachingPastTheirPlaceholder(): iterable
    {
        $uncontained = 'which does not compile as a self-contained PCRE2 fragment';
        $accepting = 'which uses (*ACCEPT)';

        yield 'an unmatched closing group' => ['a))|((', $uncontained];
        yield 'a closed and reopened group' => ['a)(?:b', $uncontained];
        yield 'an escape swallowing a backslash' => ['a\\c\\)|(', $uncontained];
        yield 'an unclosed group' => ['(a', $uncontained];
        yield 'an unterminated quote' => ['\\Qa', $uncontained];
        yield 'a duplicate capture name' => ['(?J)(?<id>a)', $uncontained];
        yield 'accept' => ['a(*ACCEPT)', $accepting];
        yield 'accept with a name' => ['a(*ACCEPT:done)', $accepting];
        yield 'accept after a class whose leading ] is literal' => ['[\\E][](*ACCEPT)]', $accepting];
    }

    #[DataProvider('fragmentsReachingPastTheirPlaceholder')]
    public function test_a_fragment_reaching_past_its_placeholder_is_rejected(string $fragment, string $message): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage($message);

        new Route('GET', '/v/{id}/tail', 'C', 'm', 200, where: ['id' => $fragment]);
    }

    /**
     * Parentheses and verb text stay literal when escaped, quoted,
     * commented or inside a class, and balanced groups stay admitted.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function selfContainedFragments(): iterable
    {
        yield 'escaped parentheses' => ['\\(\\)', '()'];
        yield 'parentheses in a class' => ['[()]+', ')('];
        yield 'a POSIX class' => ['[[:alpha:]]+', 'abc'];
        yield 'quoted verb text' => ['\\Q()(*ACCEPT)\\E', '()(*ACCEPT)'];
        yield 'commented verb text' => ['(?#(*ACCEPT)a', 'a'];
        yield 'a lookahead and a conditional' => ['(?=\\d)(?<d>\\d)(?(<d>)x|y)', '1x'];
        yield 'a reference to its own group' => ['(?<c>[a-z])\\k<c>', 'aa'];
    }

    #[DataProvider('selfContainedFragments')]
    public function test_a_self_contained_fragment_is_admitted(string $fragment, string $text): void
    {
        $route = new Route('GET', '/v/{id}/tail', 'C', 'm', 200, where: ['id' => $fragment]);

        self::assertSame(['id' => $text], $route->matchPath("/v/{$text}/tail"));
        self::assertNull($route->matchPath("/v/{$text}"));
    }

    public function test_fragments_that_compile_alone_but_not_together_are_rejected(): void
    {
        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('does not compile with its where: constraints (a: (?<n>x), b: (?<n>y))');

        new Route('GET', '/v/{a}/{b}', 'C', 'm', 200, where: ['a' => '(?<n>x)', 'b' => '(?<n>y)']);
    }

    public function test_routes_differing_only_in_constraints_claim_the_same_shape(): void
    {
        $digits = new Route('GET', '/items/{id}', 'C', 'a', 200, where: ['id' => '\d+']);
        $letters = new Route('GET', '/items/{slug}', 'C', 'b', 200, where: ['slug' => '[a-z]+']);
        $plain = new Route('GET', '/items/{item}', 'C', 'c', 200);

        self::assertSame($digits->conflictKey(), $letters->conflictKey());
        self::assertSame($digits->conflictKey(), $plain->conflictKey());
    }

    public function test_a_catch_all_ranks_like_any_placeholder(): void
    {
        $catchAll = new Route('GET', '/files/{path}', 'C', 'a', 200, where: ['path' => '.*']);
        $static = new Route('GET', '/files/readme', 'C', 'b', 200);
        $deeper = new Route('GET', '/files/{id}/meta', 'C', 'c', 200);

        self::assertLessThan(0, Route::compareForMatching($static, $catchAll));
        self::assertLessThan(0, Route::compareForMatching($deeper, $catchAll));
    }

    /**
     * A PCRE failure at match time is neither a match nor a miss. The
     * lowered backtrack limit makes the nested quantifier exhaust it
     * deterministically, with or without JIT.
     */
    public function test_a_pcre_failure_while_matching_throws_instead_of_reporting_a_miss(): void
    {
        $route = new Route('GET', '/pcre/{value}', 'C', 'm', 200, where: ['value' => '(?:a+)+[bc]']);
        $requestPath = '/pcre/' . str_repeat('a', 40);
        $limit = ini_set('pcre.backtrack_limit', '1000');
        self::assertNotFalse($limit);

        try {
            $route->matchPath($requestPath);
            self::fail('Expected a RouteMatchingException.');
        } catch (RouteMatchingException $e) {
            self::assertStringContainsString('"/pcre/{value}"', $e->getMessage());
            self::assertStringContainsString('Backtrack limit exhausted', $e->getMessage());
            self::assertStringNotContainsString($requestPath, $e->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }

        self::assertSame(['value' => 'aab'], $route->matchPath('/pcre/aab'));
    }
}
