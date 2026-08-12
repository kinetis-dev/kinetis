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

        $problems = checkResolution($manifest, 'queue', tagExists: static fn (string $repo, string $tag): bool => false);

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

        $problems = checkResolution($manifest, 'queue', tagExists: static fn (string $repo, string $tag): bool => true);

        self::assertSame([], $problems);
    }

    public function test_check_resolution_asks_for_the_exact_v_prefixed_tag(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $seen = [];
        checkResolution($manifest, 'queue', tagExists: function (string $repo, string $tag) use (&$seen): bool {
            $seen[] = [$repo, $tag];

            return true;
        });

        self::assertSame([['persistence', 'v1.4.0']], $seen);
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
}
