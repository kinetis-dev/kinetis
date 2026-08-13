<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../release-plan.php';

final class ReleasePlanTest extends TestCase
{
    public function test_finds_only_version_differing_packages(): void
    {
        $old = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
            'c' => ['version' => '1.0.0'],
        ]];
        $new = ['packages' => [
            'a' => ['version' => '1.1.0'],
            'b' => ['version' => '1.0.0'],
            'c' => ['version' => '2.0.0'],
        ]];

        self::assertSame(['a', 'c'], findReleaseCandidates($old, $new));
    }

    public function test_a_brand_new_package_with_no_prior_version_counts_as_a_candidate(): void
    {
        $old = ['packages' => ['a' => ['version' => '1.0.0']]];
        $new = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
        ]];

        self::assertSame(['b'], findReleaseCandidates($old, $new));
    }

    public function test_no_candidates_when_nothing_changed(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        self::assertSame([], findReleaseCandidates($manifest, $manifest));
    }

    public function test_finds_untagged_candidates_even_with_zero_manifest_diff(): void
    {
        // The scenario this exists for: a version already sitting in the
        // manifest, unchanged for many commits, that has genuinely never
        // been tagged — findReleaseCandidates() alone would never catch
        // this, since there's nothing to diff.
        $manifest = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
        ]];

        $candidates = findUntaggedCandidates(
            $manifest,
            tagExists: static fn (string $repo, string $tag): bool => false,
        );

        self::assertSame(['a', 'b'], $candidates);
    }

    public function test_excludes_a_package_whose_current_version_is_already_tagged(): void
    {
        $manifest = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
        ]];

        $candidates = findUntaggedCandidates(
            $manifest,
            tagExists: static fn (string $repo, string $tag): bool => $repo === 'a',
        );

        self::assertSame(['b'], $candidates);
    }

    public function test_untagged_check_asks_for_the_exact_v_prefixed_tag(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '2.3.1']]];

        $seen = [];
        findUntaggedCandidates($manifest, tagExists: function (string $repo, string $tag) use (&$seen): bool {
            $seen[] = [$repo, $tag];

            return true;
        });

        self::assertSame([['a', 'v2.3.1']], $seen);
    }

    public function test_a_version_that_genuinely_has_no_matching_tag_stops_being_untagged_once_it_does(): void
    {
        // Confirms the "self-healing, no bookkeeping needed" claim in
        // findUntaggedCandidates()'s own doc comment directly: the exact
        // same package/version, checked twice, comes back candidate then
        // clean purely because the injected tagExists() answer changed —
        // nothing about the package itself had to be told it's released.
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        self::assertSame(
            ['a'],
            findUntaggedCandidates($manifest, tagExists: static fn (string $r, string $t): bool => false),
        );
        self::assertSame(
            [],
            findUntaggedCandidates($manifest, tagExists: static fn (string $r, string $t): bool => true),
        );
    }

    public function test_publish_order_respects_dependency_order_between_two_candidates(): void
    {
        $manifest = ['packages' => [
            'persistence' => ['requires' => ['kinetis']],
            'kinetis' => ['requires' => []],
            'queue' => ['requires' => ['kinetis', 'persistence']],
            'unrelated' => ['requires' => []],
        ]];

        $order = publishOrder($manifest, ['persistence', 'queue']);

        self::assertSame(['persistence', 'queue'], $order);
    }

    public function test_publish_order_excludes_non_candidates_even_if_related(): void
    {
        $manifest = ['packages' => [
            'persistence' => ['requires' => ['kinetis']],
            'kinetis' => ['requires' => []],
            'queue' => ['requires' => ['kinetis', 'persistence']],
        ]];

        // kinetis isn't a candidate this round, even though queue depends
        // on it — publishOrder must not pull it in.
        $order = publishOrder($manifest, ['persistence', 'queue']);

        self::assertNotContains('kinetis', $order);
        self::assertCount(2, $order);
    }

    public function test_publish_order_includes_independent_candidates_regardless_of_relationship(): void
    {
        $manifest = ['packages' => [
            'kinetis' => ['requires' => []],
            'unrelated' => ['requires' => []],
        ]];

        $order = publishOrder($manifest, ['kinetis', 'unrelated']);

        self::assertCount(2, $order);
        self::assertContains('kinetis', $order);
        self::assertContains('unrelated', $order);
    }

    public function test_check_resolution_reports_a_problem_for_each_sibling_whose_tag_is_missing(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => ['kinetis']],
            'persistence' => ['version' => '1.4.0'],
            'kinetis' => ['version' => '2.0.0'],
        ]];

        $problems = checkResolution($manifest, 'queue', candidateSet: [], tagExists: static fn (string $repo, string $tag): bool => false);

        self::assertCount(2, $problems);
        self::assertStringContainsString('persistence (v1.4.0)', $problems[0]);
        self::assertStringContainsString('kinetis (v2.0.0)', $problems[1]);
    }

    public function test_check_resolution_reports_nothing_when_every_sibling_tag_exists(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $problems = checkResolution($manifest, 'queue', candidateSet: [], tagExists: static fn (string $repo, string $tag): bool => true);

        self::assertSame([], $problems);
    }

    public function test_check_resolution_asks_for_the_exact_v_prefixed_tag(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $seen = [];
        checkResolution($manifest, 'queue', candidateSet: [], tagExists: function (string $repo, string $tag) use (&$seen): bool {
            $seen[] = [$repo, $tag];

            return true;
        });

        self::assertSame([['persistence', 'v1.4.0']], $seen);
    }

    public function test_a_sibling_that_is_also_a_candidate_this_round_is_not_flagged_as_unresolved(): void
    {
        // The scenario this fix exists for: an interdependent first
        // release, where a sibling genuinely has no tag yet because it's
        // being published earlier in this exact same run — a real
        // callback reporting false for it (as it honestly would, since
        // no tag exists) must not turn into a resolution problem.
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $problems = checkResolution(
            $manifest,
            'queue',
            candidateSet: ['persistence' => true],
            tagExists: static fn (string $repo, string $tag): bool => false,
        );

        self::assertSame([], $problems);
    }

    public function test_a_same_round_candidate_sibling_never_reaches_the_tag_exists_callback(): void
    {
        // Not just "the result is empty" — confirms the live-tag check
        // is skipped entirely for a same-round sibling, not performed
        // and then discarded. Matters in practice: the real callback is
        // a network call, and every candidate in a from-scratch release
        // has only same-round siblings, so this is the common case, not
        // the exception.
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $called = false;
        checkResolution(
            $manifest,
            'queue',
            candidateSet: ['persistence' => true],
            tagExists: function (string $repo, string $tag) use (&$called): bool {
                $called = true;

                return false;
            },
        );

        self::assertFalse($called);
    }

    public function test_a_mix_of_same_round_and_already_published_siblings_checks_only_the_latter(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence', 'kinetis'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
            'kinetis' => ['version' => '2.0.0'],
        ]];

        $seen = [];
        $problems = checkResolution(
            $manifest,
            'queue',
            // persistence is a same-round candidate (skipped); kinetis
            // is not (still genuinely checked).
            candidateSet: ['queue' => true, 'persistence' => true],
            tagExists: function (string $repo, string $tag) use (&$seen): bool {
                $seen[] = [$repo, $tag];

                return true;
            },
        );

        self::assertSame([['kinetis', 'v2.0.0']], $seen);
        self::assertSame([], $problems);
    }

    public function test_topological_order_places_foundational_packages_first(): void
    {
        $graph = [
            'kinetis' => [],
            'persistence' => ['kinetis'],
            'queue' => ['kinetis', 'persistence'],
        ];

        $order = topologicalOrder($graph);

        self::assertSame(['kinetis', 'persistence', 'queue'], $order);
    }

    /**
     * Real network call, against the real, currently tag-less
     * kinetis-dev/kinetis repo — confirms tagExistsOnGitHub() correctly
     * reports false rather than being hardcoded to always report true or
     * throwing. The positive ("a real tag is found") path is verified
     * separately, by hand, against a well-known tagged public repo — not
     * committed here, since asserting on another project's own tag
     * history is fragile and not this project's concern to keep passing.
     */
    public function test_tag_exists_on_github_reports_false_for_a_tag_that_really_does_not_exist(): void
    {
        self::assertFalse(tagExistsOnGitHub('kinetis', 'v999.999.999'));
    }

    public function test_print_json_reports_ok_true_and_the_full_plan_when_every_candidate_resolves(): void
    {
        $plan = [
            ['key' => 'persistence', 'version' => '1.0.0', 'problems' => []],
            ['key' => 'queue', 'version' => '1.0.0', 'problems' => []],
        ];

        ob_start();
        printJson($plan);
        $output = ob_get_clean();
        self::assertIsString($output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['ok']);
        self::assertSame($plan, $decoded['candidates']);
    }

    public function test_print_json_reports_ok_false_when_any_candidate_has_a_problem(): void
    {
        $plan = [
            ['key' => 'persistence', 'version' => '1.0.0', 'problems' => []],
            ['key' => 'queue', 'version' => '1.0.0', 'problems' => ['queue requires persistence (v1.0.0), but that tag doesn\'t exist on kinetis-dev/persistence yet']],
        ];

        ob_start();
        printJson($plan);
        $output = ob_get_clean();
        self::assertIsString($output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['ok']);
    }

    public function test_print_json_with_no_candidates_reports_an_empty_list_and_ok_true(): void
    {
        ob_start();
        printJson([]);
        $output = ob_get_clean();
        self::assertIsString($output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['candidates']);
        self::assertTrue($decoded['ok']);
    }

    public function test_print_human_readable_lists_each_candidate_with_its_own_problems(): void
    {
        $plan = [
            ['key' => 'persistence', 'version' => '1.0.0', 'problems' => []],
            ['key' => 'queue', 'version' => '1.0.0', 'problems' => ['queue requires persistence (v1.0.0), but that tag doesn\'t exist on kinetis-dev/persistence yet']],
        ];

        ob_start();
        printHumanReadable($plan, note: null);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('persistence -> v1.0.0', $output);
        self::assertStringContainsString('queue -> v1.0.0', $output);
        self::assertStringContainsString('[resolution] queue requires persistence', $output);
        // persistence has no problems, so its own line carries no
        // [resolution] entry — confirming problems are grouped under the
        // candidate they actually belong to, not lumped together.
        self::assertStringNotContainsString("persistence -> v1.0.0\n    [resolution]", $output);
    }

    public function test_print_human_readable_prints_the_given_note_instead_of_a_plan(): void
    {
        ob_start();
        printHumanReadable([['key' => 'x', 'version' => '1.0.0', 'problems' => []]], note: 'Nothing to compare.');
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertSame("Nothing to compare.\n", $output);
    }

    public function test_print_human_readable_with_no_candidates_and_no_note_reports_nothing_to_release(): void
    {
        ob_start();
        printHumanReadable([], note: null);
        $output = ob_get_clean();
        self::assertIsString($output);

        self::assertStringContainsString('Nothing to release', $output);
        self::assertStringContainsString('already tagged', $output);
    }
}
