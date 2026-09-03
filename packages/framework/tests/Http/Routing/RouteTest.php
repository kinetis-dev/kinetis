<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\InvalidRouteConstraintException;
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

    public function test_a_constrained_placeholder_only_matches_its_own_pattern(): void
    {
        $route = new Route('GET', '/products/{id:\d+}', 'C', 'm', 200);

        self::assertSame(['id' => '42'], $route->matchPath('/products/42'));
        self::assertNull($route->matchPath('/products/abc'));
    }

    public function test_a_constrained_placeholder_pattern_can_span_a_fixed_length(): void
    {
        $route = new Route('GET', '/orders/{code:[0-9a-f]{8}}', 'C', 'm', 200);

        self::assertSame(['code' => 'deadbeef'], $route->matchPath('/orders/deadbeef'));
        self::assertNull($route->matchPath('/orders/deadbee'));
        self::assertNull($route->matchPath('/orders/deadbeefg'));
    }

    public function test_path_parameter_pattern_returns_the_raw_constraint_for_a_constrained_placeholder(): void
    {
        $route = new Route('GET', '/products/{id:\d+}', 'C', 'm', 200);

        self::assertSame('\d+', $route->pathParameterPattern('id'));
    }

    public function test_path_parameter_pattern_returns_null_for_a_plain_placeholder(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertNull($route->pathParameterPattern('id'));
    }

    public function test_path_parameter_pattern_returns_null_for_an_unknown_name(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertNull($route->pathParameterPattern('missing'));
    }

    public function test_path_parameter_names_strips_the_constraint_suffix(): void
    {
        $route = new Route('GET', '/products/{id:\d+}/{sku}', 'C', 'm', 200);

        self::assertSame(['id', 'sku'], $route->pathParameterNames());
    }

    public function test_open_api_path_template_strips_every_constraint_back_to_a_plain_placeholder(): void
    {
        $route = new Route('GET', '/products/{id:\d+}/{sku:[a-z-]+}', 'C', 'm', 200);

        self::assertSame('/products/{id}/{sku}', $route->openApiPathTemplate());
    }

    public function test_open_api_path_template_is_unchanged_when_no_placeholder_has_a_constraint(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame('/users/{id}', $route->openApiPathTemplate());
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
        $this->expectException(InvalidRouteConstraintException::class);
        $this->expectExceptionMessage('declares the placeholder "{id}" more than once');

        new Route('GET', '/users/{id}/orders/{id}', 'C', 'm', 200);
    }

    public function test_a_malformed_constraint_pattern_throws_at_construction_not_at_match_time(): void
    {
        $this->expectException(InvalidRouteConstraintException::class);
        $this->expectExceptionMessage('is not a valid regex fragment');

        // Unbalanced parenthesis -- a real PCRE compile error, not a
        // pattern that just happens to match nothing.
        new Route('GET', '/products/{id:(unclosed}', 'C', 'm', 200);
    }

    public function test_a_valid_constraint_pattern_still_constructs_and_matches_correctly(): void
    {
        // Proves the new validation doesn't reject anything it shouldn't
        // -- every existing constrained-placeholder test already covers
        // this too, but this one pins it against the exact malformed
        // sibling case above.
        $route = new Route('GET', '/products/{id:\d+}', 'C', 'm', 200);

        self::assertSame(['id' => '42'], $route->matchPath('/products/42'));
    }

    /**
     * findMatchingBrace()'s own naive brace counting used to close the
     * placeholder one character early here: the "\}" inside the
     * constraint is a PCRE escape for a literal "}" character, not this
     * scanner's own nesting syntax, so it must be skipped as a pair
     * rather than counted toward depth.
     */
    public function test_a_constraint_pattern_containing_an_escaped_literal_brace_is_parsed_correctly(): void
    {
        $route = new Route('GET', '/x/{value:\}}', 'C', 'm', 200);

        self::assertSame('\}', $route->pathParameterPattern('value'));
        self::assertSame(['value' => '}'], $route->matchPath('/x/}'));
    }

    /**
     * compile() used to wrap every route's compiled regex in a hardcoded
     * "#...#" delimiter — a real, valid PCRE character class containing a
     * literal "#" (a perfectly ordinary character to allow/deny inside
     * one) terminated that delimiter early, corrupting the whole compiled
     * pattern and getting rejected as "malformed" for a reason that had
     * nothing to do with the pattern itself.
     */
    public function test_a_constraint_pattern_containing_the_delimiter_character_is_parsed_correctly(): void
    {
        $route = new Route('GET', '/x/{value:[A-F#]+}', 'C', 'm', 200);

        self::assertSame('[A-F#]+', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'A#F'], $route->matchPath('/x/A#F'));
    }

    /**
     * findMatchingBrace() used to count every "{"/"}" toward nesting
     * depth unconditionally, including inside a PCRE character class —
     * where neither has any special meaning at all, both are just
     * ordinary members of the class, the same as any other character.
     * The "{" inside "[{]" incorrectly opened a second nesting level,
     * consuming the placeholder's own real closing "}" as if it closed
     * that phantom level instead — the route registered without error,
     * but matched the wrong thing entirely (worse than a construction-
     * time rejection, since nothing signals the mistake).
     */
    public function test_a_brace_inside_a_character_class_is_not_treated_as_nesting(): void
    {
        $route = new Route('GET', '/x/{value:[{]}', 'C', 'm', 200);

        self::assertSame('[{]', $route->pathParameterPattern('value'));
        self::assertSame(['value' => '{'], $route->matchPath('/x/{'));
        self::assertNull($route->matchPath('/x/}'));
    }

    /**
     * chooseDelimiter()'s old finite-candidate-list design failed
     * outright once a pattern used every single one of its candidates —
     * a real, constructible case, not a hypothetical: a character class
     * is free to list any set of characters, including precisely the
     * ones a fixed candidate list offers. escapeDelimiter() has no such
     * ceiling: it works for any input by escaping the delimiter wherever
     * it appears, rather than trying to dodge it.
     */
    public function test_a_constraint_pattern_using_every_former_delimiter_candidate_still_parses(): void
    {
        $route = new Route('GET', '/x/{value:[#~!%@|+\-=]+}', 'C', 'm', 200);

        self::assertSame('[#~!%@|+\-=]+', $route->pathParameterPattern('value'));
        self::assertSame(['value' => '#~!%@|+-='], $route->matchPath('/x/#~!%@|+-='));
    }

    /**
     * An even run of backslashes pairs off completely, leaving the
     * following "{" genuinely unescaped — real nesting, exercised here
     * by a fixed-length quantifier applying to the escaped-backslash
     * atom "\\" it follows (matching two literal backslash characters,
     * confirmed directly against a real preg_match() call before
     * trusting this as the correct expectation, not assumed).
     */
    public function test_an_even_backslash_run_before_a_brace_leaves_it_as_real_nesting(): void
    {
        $route = new Route('GET', '/x/{value:a\\\\{2}}', 'C', 'm', 200);

        self::assertSame('a\\\\{2}', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'a\\\\'], $route->matchPath('/x/a\\\\'));
    }

    /**
     * An odd run leaves exactly one backslash escaping the brace that
     * follows — here a single backslash before each of "{"/"}",
     * matching the literal three-character string "{2}" (confirmed
     * directly against a real preg_match() call first, not assumed).
     * Neither brace opens or closes any nesting at all in this case.
     */
    public function test_an_odd_backslash_run_before_a_brace_leaves_it_escaped(): void
    {
        $route = new Route('GET', '/x/{value:\\{2\\}}', 'C', 'm', 200);

        self::assertSame('\\{2\\}', $route->pathParameterPattern('value'));
        self::assertSame(['value' => '{2}'], $route->matchPath('/x/{2}'));
    }

    /**
     * "#" was this class's own first delimiter choice, reverted once a
     * real counterexample surfaced: "(?#...)" is a PCRE comment group,
     * not just an ordinary occurrence of "#" — escaping it (the only
     * thing a "#" delimiter could do to keep the pattern's own end from
     * being mistaken early) corrupts "(?#note)" into "(?\#note)", which
     * no longer opens a comment group at all and fails to compile. "~"
     * (this class's delimiter now) has no PCRE-syntactic role anywhere,
     * so the comment group is left completely untouched and the pattern
     * still matches correctly.
     */
    public function test_a_constraint_pattern_containing_a_pcre_comment_group_is_parsed_correctly(): void
    {
        $route = new Route('GET', '/x/{value:(?#note)a}', 'C', 'm', 200);

        self::assertSame('(?#note)a', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'a'], $route->matchPath('/x/a'));
    }

    /**
     * A POSIX bracket-expression sub-form ("[:alpha:]", a named
     * character class, valid standalone PCRE) nested inside an outer
     * character class carries its own ":]" closer — mistaking it for the
     * outer class's own closing "]" (as findMatchingBrace() used to)
     * reopens brace-depth counting one character early, treating the
     * outer class's own literal "{" as real nesting and corrupting
     * everything after it. matchPath() is the sharper of the two checks
     * here: pathParameterPattern() can still report a plausible-looking
     * fragment even when the underlying scan went wrong, but a route
     * that silently matches the wrong thing (or nothing) is the actual,
     * user-visible failure.
     */
    public function test_a_constraint_pattern_containing_a_posix_character_class_is_parsed_correctly(): void
    {
        $route = new Route('GET', '/x/{value:[[:alpha:]{]}', 'C', 'm', 200);

        self::assertSame('[[:alpha:]{]', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'a'], $route->matchPath('/x/a'));
        self::assertSame(['value' => '{'], $route->matchPath('/x/{'));
        self::assertNull($route->matchPath('/x/}'));
    }

    /**
     * "\Q...\E" marks a literal-text span in real PCRE — neither the "{"
     * nor the "}" inside carries any nesting meaning there, the same
     * "ordinary character, not this scanner's own syntax" treatment
     * findMatchingBrace() already gives a character class's own "{"/"}".
     */
    public function test_a_constraint_pattern_containing_a_literal_quoted_span_is_parsed_correctly(): void
    {
        $route = new Route('GET', '/x/{value:a\Q{\E}', 'C', 'm', 200);

        self::assertSame('a\Q{\E', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'a{'], $route->matchPath('/x/a{'));
    }

    /**
     * Inside a "\Q...\E" span a backslash is ordinary literal text, not
     * an escape — so escaping the delimiter there the ordinary way turns
     * the caller's one literal "~" into a backslash *and* a tilde, and
     * the route silently stops matching what its own reported pattern
     * says it matches. escapeDelimiter() closes and reopens the span
     * around the escape instead. Both halves are asserted: the tilde
     * matches, and the backslash-tilde the naive escaping would have
     * demanded does not.
     */
    public function test_the_delimiter_inside_a_quoted_span_still_matches_one_literal_character(): void
    {
        $route = new Route('GET', '/x/{value:\Q~\E}', 'C', 'm', 200);

        self::assertSame('\Q~\E', $route->pathParameterPattern('value'));
        self::assertSame(['value' => '~'], $route->matchPath('/x/~'));
        self::assertNull($route->matchPath('/x/\~'));
    }

    /**
     * A "(?#...)" comment group is text PCRE discards outright, so the
     * "}" inside one is comment text rather than the placeholder's own
     * closing brace — the scanner used to close the placeholder on it,
     * leaving a truncated "(?#" fragment that was then reported as
     * malformed.
     */
    public function test_a_brace_inside_a_pcre_comment_group_is_not_the_placeholder_close(): void
    {
        $route = new Route('GET', '/x/{value:(?#})a}', 'C', 'm', 200);

        self::assertSame('(?#})a', $route->pathParameterPattern('value'));
        self::assertSame(['value' => 'a'], $route->matchPath('/x/a'));
    }

    /**
     * @return list<array{string}>
     */
    public static function extendedModePatterns(): array
    {
        return [
            ['(?x) a'],
            ['(?x: a )'],
            ['(?imx: a )'],
            ['(?^x: a )'],
        ];
    }

    /**
     * Extended mode is rejected at registration rather than lexed: its
     * "#"-to-end-of-line comments would hide a "}" behind flag scope,
     * which is parser state rather than a construct with a fixed opener
     * and closer the brace scanner could skip over.
     */
    #[DataProvider('extendedModePatterns')]
    public function test_a_constraint_pattern_turning_on_extended_mode_is_rejected(string $pattern): void
    {
        $this->expectException(InvalidRouteConstraintException::class);
        $this->expectExceptionMessage("turns on PCRE's extended mode");

        new Route('GET', '/x/{value:' . $pattern . '}', 'C', 'm', 200);
    }

    /**
     * @return list<array{string}>
     */
    public static function extendedModeDisablingPatterns(): array
    {
        return [
            ['(?-x: a )'],
            ['(?im-sx: a )'],
        ];
    }

    /**
     * Everything after a flag run's "-" is being turned *off*, so these
     * disable extended mode rather than enabling it — confirmed against
     * real preg_match() on both supported PHP versions, since reading
     * them the other way is precisely the inversion this scanner once
     * shipped with. They must register and match, and the space in the
     * fragment must still be significant: extended mode is what would
     * have made it optional.
     */
    #[DataProvider('extendedModeDisablingPatterns')]
    public function test_a_flag_run_disabling_extended_mode_registers_normally(string $pattern): void
    {
        $route = new Route('GET', '/x/{value:' . $pattern . '}', 'C', 'm', 200);

        self::assertSame(['value' => ' a '], $route->matchPath('/x/ a '));
        self::assertNull($route->matchPath('/x/a'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function unsupportedTokensAsLiteralText(): array
    {
        return [
            // A character class's members are ordinary characters
            // however they are spelled — neither of these opens
            // anything.
            ['[(*]', '('],
            ['[(?x)]', 'x'],
            // A quoted span is literal text end to end.
            ['\Q(*\E', '(*'],
            ['\Q(?x)\E', '(?x)'],
            // A comment group's body is discarded outright.
            ['(?#(*)a', 'a'],
        ];
    }

    /**
     * The unsupported-construct check reads only text that is actually
     * syntax. A "(*" or "(?x)" appearing as ordinary literal text —
     * members of a character class, the contents of a quoted span, the
     * body of a comment group — spells a construct without being one,
     * and rejecting it was both a refusal of a valid route and an error
     * that said something untrue about the pattern.
     *
     * It also broke the grammar's own composition: a character class is
     * documented as supported, and it must not stop being supported
     * because of which ordinary members it holds.
     */
    #[DataProvider('unsupportedTokensAsLiteralText')]
    public function test_an_unsupported_token_as_literal_text_is_not_mistaken_for_the_construct(
        string $pattern,
        string $subject,
    ): void {
        $route = new Route('GET', '/x/{value:' . $pattern . '}', 'C', 'm', 200);

        self::assertSame($pattern, $route->pathParameterPattern('value'));
        self::assertSame(['value' => $subject], $route->matchPath('/x/' . $subject));
    }

    /**
     * "\Q...\E" is processed inside a character class too, so the "]"
     * in "[\Q]\E]" is a member rather than the class's own close —
     * confirmed against real preg_match(). Skipping the span only
     * outside a class ends the class early and takes the placeholder's
     * own brace with it.
     */
    public function test_a_quoted_span_inside_a_character_class_does_not_end_it_early(): void
    {
        $route = new Route('GET', '/x/{value:[\Q]\E{]}', 'C', 'm', 200);

        self::assertSame('[\Q]\E{]', $route->pathParameterPattern('value'));
        self::assertSame(['value' => ']'], $route->matchPath('/x/]'));
        self::assertSame(['value' => '{'], $route->matchPath('/x/{'));
    }

    /**
     * A "(*...)" verb's shape depends on which verb it is — "(*MARK:})"
     * ends at its first ")" while "(*atomic:a(b))" nests — so the
     * scanner refuses them by name rather than mis-reading one as a
     * malformed fragment the caller wrote wrong.
     */
    public function test_a_constraint_pattern_using_a_control_verb_is_rejected(): void
    {
        $this->expectException(InvalidRouteConstraintException::class);
        $this->expectExceptionMessage('uses a PCRE control verb');

        new Route('GET', '/x/{value:(*MARK:})a}', 'C', 'm', 200);
    }

    /**
     * The extended-mode scan keys on a real inline *flag* group, not on
     * an "x" appearing anywhere in a "(?...)" construct — a group merely
     * named "x", or a non-capturing/lookaround group, still registers
     * normally.
     */
    public function test_a_group_named_x_is_not_mistaken_for_extended_mode(): void
    {
        $route = new Route('GET', '/x/{value:(?<x>a)(?:b)(?=c)c}', 'C', 'm', 200);

        self::assertSame(['value' => 'abc'], $route->matchPath('/x/abc'));
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

    public function test_compare_for_matching_ranks_a_static_segment_ahead_of_a_constrained_placeholder(): void
    {
        $static = new Route('GET', '/users/self', 'C', 'a', 200);
        $constrained = new Route('GET', '/users/{id:\d+}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($static, $constrained));
        self::assertGreaterThan(0, Route::compareForMatching($constrained, $static));
    }

    public function test_compare_for_matching_ranks_a_constrained_placeholder_ahead_of_an_unconstrained_one(): void
    {
        $constrained = new Route('GET', '/users/{id:\d+}', 'C', 'a', 200);
        $unconstrained = new Route('GET', '/users/{id}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($constrained, $unconstrained));
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

    public function test_compare_for_matching_ranks_a_mixed_segment_ahead_of_an_unconstrained_pure_placeholder(): void
    {
        $mixed = new Route('GET', '/files/report-{id}.pdf', 'C', 'a', 200);
        $pure = new Route('GET', '/files/{id}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($mixed, $pure));
    }

    public function test_compare_for_matching_ranks_a_mixed_segment_ahead_of_a_constrained_pure_placeholder(): void
    {
        // The tier (literal > mixed > pure placeholder) is a strict,
        // primary ordering — an unconstrained mixed segment still beats
        // a *constrained* pure-placeholder segment. Constraint status
        // only breaks a tie within the same tier, it never crosses one.
        $mixed = new Route('GET', '/files/report-{id}.pdf', 'C', 'a', 200);
        $pureConstrained = new Route('GET', '/files/{id:\d+}', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($mixed, $pureConstrained));
    }

    public function test_compare_for_matching_ranks_a_constrained_mixed_segment_ahead_of_an_unconstrained_one(): void
    {
        $constrainedMixed = new Route('GET', '/files/report-{id:\d+}.pdf', 'C', 'a', 200);
        $unconstrainedMixed = new Route('GET', '/files/report-{id}.pdf', 'C', 'b', 200);

        self::assertLessThan(0, Route::compareForMatching($constrainedMixed, $unconstrainedMixed));
    }

    public function test_a_slash_inside_a_constraint_pattern_does_not_split_it_into_multiple_url_segments(): void
    {
        $route = new Route('GET', '/files/{path:[a-z/]+}', 'C', 'm', 200);

        self::assertSame(['path' => 'a/b/c'], $route->matchPath('/files/a/b/c'));
    }

    public function test_compare_for_matching_does_not_let_a_slash_inside_a_constraint_inflate_its_segment_count(): void
    {
        // "{a:p/q}" is one real URL segment despite the "/" its own
        // constraint pattern legally contains (constraints are raw,
        // unanchored PCRE with no special meaning for "/"). A naive
        // explode('/', ...) on the raw template would fragment it into
        // two fake segments, tying this route's segment count with the
        // genuinely two-segment sibling below instead of correctly
        // trailing it by one.
        $onePlaceholderSegment = new Route('GET', '/x/{a:p/q}', 'C', 'a', 200);
        $twoLiteralSegments = new Route('GET', '/x/{a:pq}/extra', 'C', 'b', 200);

        // Both share "x" at position 0 and an equally-constrained
        // placeholder at position 1 (the constraint's own content
        // doesn't affect tier scoring, only whether one exists) — tied
        // until the segment-count fallback, which only resolves
        // correctly if the slash inside {a:p/q} was never treated as a
        // segment boundary.
        self::assertGreaterThan(0, Route::compareForMatching($onePlaceholderSegment, $twoLiteralSegments));
    }
}
