<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;
use PublicationRefs;

require_once __DIR__ . '/../release-plan.php';

final class ReleasePlanTest extends TestCase
{
    public function test_a_changed_version_is_a_candidate(): void
    {
        self::assertSame(
            ['demo'],
            findReleaseCandidates(self::manifest(['demo' => '1.0.0']), self::manifest(['demo' => '1.0.1'])),
        );
    }

    public function test_an_unchanged_version_is_not(): void
    {
        self::assertSame(
            [],
            findReleaseCandidates(self::manifest(['demo' => '1.0.0']), self::manifest(['demo' => '1.0.0'])),
        );
    }

    public function test_a_package_with_no_previous_entry_is_a_candidate(): void
    {
        self::assertSame(['demo'], findReleaseCandidates(['packages' => []], self::manifest(['demo' => '1.0.0'])));
    }

    public function test_a_repository_carrying_the_tag_and_a_matching_main_is_published(): void
    {
        self::assertTrue(new PublicationRefs(main: 'abc', tag: 'abc')->isPublished());
    }

    public function test_a_missing_tag_is_unpublished(): void
    {
        self::assertFalse(new PublicationRefs(main: 'abc', tag: null)->isPublished());
    }

    public function test_a_tag_without_a_matching_main_is_unpublished(): void
    {
        self::assertFalse(new PublicationRefs(main: 'old', tag: 'abc')->isPublished());
    }

    public function test_an_empty_repository_is_unpublished(): void
    {
        self::assertFalse(new PublicationRefs(main: null, tag: null)->isPublished());
    }

    public function test_a_fully_published_package_is_not_a_candidate(): void
    {
        $refs = self::refs(['demo' => new PublicationRefs(main: 'abc', tag: 'abc')]);

        self::assertSame([], findUnpublishedCandidates(self::manifest(['demo' => '1.0.0']), $refs));
    }

    public function test_a_version_that_predates_the_pipeline_is_still_a_candidate(): void
    {
        $refs = self::refs(['demo' => new PublicationRefs(main: null, tag: null)]);

        self::assertSame(['demo'], findUnpublishedCandidates(self::manifest(['demo' => '1.0.0']), $refs));
    }

    public function test_a_tagged_package_whose_main_never_landed_is_a_candidate(): void
    {
        $refs = self::refs(['demo' => new PublicationRefs(main: 'old', tag: 'abc')]);

        self::assertSame(['demo'], findUnpublishedCandidates(self::manifest(['demo' => '1.0.0']), $refs));
    }

    public function test_publish_order_puts_a_dependency_before_its_dependent(): void
    {
        $manifest = ['packages' => [
            'pingpong' => ['requires' => ['framework']],
            'framework' => [],
        ]];

        self::assertSame(['framework', 'pingpong'], publishOrder($manifest, ['pingpong', 'framework']));
    }

    public function test_publish_order_keeps_only_the_candidates(): void
    {
        $manifest = ['packages' => [
            'framework' => [],
            'persistence' => ['requires' => ['framework']],
            'pingpong' => ['requires' => ['persistence']],
        ]];

        self::assertSame(['framework', 'pingpong'], publishOrder($manifest, ['pingpong', 'framework']));
    }

    public function test_a_graph_with_no_total_order_fails_rather_than_dropping_candidates(): void
    {
        $this->expectExceptionMessage('has no publish order');

        publishOrder(['packages' => [
            'a' => ['requires' => ['b']],
            'b' => ['requires' => ['a']],
        ]], ['a', 'b']);
    }

    public function test_a_sibling_tagged_on_its_own_repository_resolves(): void
    {
        $manifest = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.1']);
        $manifest['packages']['persistence']['requires'] = ['framework'];
        $refs = self::refs(['framework' => new PublicationRefs(main: 'abc', tag: 'abc')]);

        self::assertSame([], checkResolution($manifest, 'persistence', ['persistence' => true], $refs));
    }

    public function test_an_untagged_sibling_that_is_not_releasing_is_a_problem(): void
    {
        $manifest = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.1']);
        $manifest['packages']['persistence']['requires'] = ['framework'];
        $refs = self::refs(['framework' => new PublicationRefs(main: null, tag: null)]);

        $problems = checkResolution($manifest, 'persistence', ['persistence' => true], $refs);

        self::assertSame(
            ["persistence requires framework (v1.4.2), but that tag doesn't exist on kinetis-dev/framework yet"],
            $problems,
        );
    }

    public function test_a_sibling_releasing_in_the_same_round_resolves_without_a_tag(): void
    {
        $manifest = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.1']);
        $manifest['packages']['persistence']['requires'] = ['framework'];
        $refs = self::refs([]);

        $problems = checkResolution($manifest, 'persistence', ['framework' => true, 'persistence' => true], $refs);

        self::assertSame([], $problems);
    }

    public function test_a_dev_sibling_is_checked_too(): void
    {
        $manifest = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.1']);
        $manifest['packages']['persistence']['requiresDev'] = ['framework'];
        $refs = self::refs(['framework' => new PublicationRefs(main: null, tag: null)]);

        self::assertCount(1, checkResolution($manifest, 'persistence', ['persistence' => true], $refs));
    }

    public function test_the_two_sources_union_rather_than_shadow_each_other(): void
    {
        $manifest = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.1']);
        $old = self::manifest(['framework' => '1.4.2', 'persistence' => '1.0.0']);
        $refs = self::refs([
            'persistence' => new PublicationRefs(main: null, tag: null),
            'framework' => new PublicationRefs(main: null, tag: null),
        ]);

        $plan = buildPlan($manifest, $old, $refs, self::published([]));

        self::assertSame(['framework', 'persistence'], array_column($plan, 'key'));
    }

    public function test_a_round_with_nothing_to_do_produces_an_empty_plan(): void
    {
        $manifest = self::manifest(['demo' => '1.0.0']);
        $refs = self::refs(['demo' => new PublicationRefs(main: 'abc', tag: 'abc')]);

        self::assertSame([], buildPlan($manifest, $manifest, $refs, self::published([])));
    }

    public function test_a_first_release_needs_no_earlier_manifest(): void
    {
        $manifest = self::manifest(['demo' => '1.0.0']);
        $refs = self::refs(['demo' => new PublicationRefs(main: null, tag: null)]);

        self::assertSame(['demo'], array_column(buildPlan($manifest, null, $refs, self::published([])), 'key'));
    }

    public function test_the_published_versions_are_read_out_of_a_tag_listing(): void
    {
        $listing = implode("\n", [
            "aaa\trefs/tags/v1.0.0",
            "bbb\trefs/tags/v1.1.0",
            "ccc\trefs/tags/v1.0.0-beta",
            "ddd\trefs/tags/v2.0.0",
            "eee\trefs/tags/vnot-a-version",
            '',
        ]);

        self::assertSame(['1.0.0', '1.1.0'], versionTags($listing));
    }

    public function test_the_first_version_a_repository_publishes_is_1_0_0(): void
    {
        self::assertSame([], checkPredecessor('demo', '1.0.0', []));
    }

    public function test_a_repository_with_no_tags_does_not_start_partway_up_its_own_line(): void
    {
        $problems = checkPredecessor('demo', '1.4.0', []);

        self::assertSame(
            ["demo v1.4.0 does not follow what kinetis-dev/demo publishes — a new package starts at 1.0.0, not '1.4.0'"],
            $problems,
        );
    }

    public function test_a_version_one_step_above_the_published_line_publishes(): void
    {
        self::assertSame([], checkPredecessor('demo', '1.0.2', ['1.0.0', '1.0.1']));
        self::assertSame([], checkPredecessor('demo', '1.1.0', ['1.0.0', '1.0.1']));
    }

    /**
     * The release workflow keeps one pending run per group, so a version
     * can be superseded on main before its own run ever starts. Its tag
     * is then missing under the version that replaced it, and nothing in
     * the manifest can say so.
     */
    public function test_a_version_whose_predecessor_was_never_published_fails(): void
    {
        $problems = checkPredecessor('demo', '1.0.2', ['1.0.0']);

        self::assertSame(
            ['demo v1.0.2 does not follow what kinetis-dev/demo publishes — version jumped from 1.0.0 to 1.0.2 — '
                . 'the only steps allowed are 1.0.1 or 1.1.0, so every version in between stays reachable'],
            $problems,
        );
    }

    public function test_a_repository_already_publishing_a_higher_version_fails(): void
    {
        $problems = checkPredecessor('demo', '1.1.0', ['1.0.0', '1.2.0']);

        self::assertSame(
            ['demo v1.1.0 does not follow what kinetis-dev/demo publishes — version 1.1.0 is lower than 1.2.0'],
            $problems,
        );
    }

    /**
     * The repair path: a tag that landed without its branch is this
     * round's work, and nothing can be missing under a version that is
     * already published.
     */
    public function test_a_version_already_tagged_is_left_to_the_publication(): void
    {
        self::assertSame([], checkPredecessor('demo', '1.4.0', ['1.3.0', '1.4.0']));
    }

    public function test_the_highest_published_version_is_the_predecessor(): void
    {
        self::assertSame('1.10.0', highestVersion(['1.9.3', '1.10.0', '1.2.0']));
        self::assertNull(highestVersion([]));
    }

    public function test_a_candidate_carries_its_predecessor_problem_into_the_plan(): void
    {
        $manifest = self::manifest(['demo' => '1.0.2']);
        $refs = self::refs(['demo' => new PublicationRefs(main: null, tag: null)]);

        $plan = buildPlan($manifest, null, $refs, self::published(['demo' => ['1.0.0']]));

        self::assertSame(['demo'], array_column($plan, 'key'));
        self::assertStringContainsString('version jumped from 1.0.0 to 1.0.2', $plan[0]['problems'][0]);
    }

    /**
     * @param array<string, string> $versions
     * @return array<string, mixed>
     */
    private static function manifest(array $versions): array
    {
        $packages = [];

        foreach ($versions as $key => $version) {
            $packages[$key] = ['name' => "kinetis/{$key}", 'version' => $version];
        }

        return ['packages' => $packages];
    }

    /**
     * @param array<string, list<string>> $byKey packages not named here
     *        publish nothing
     * @return callable(string): list<string>
     */
    private static function published(array $byKey): callable
    {
        return static fn (string $key): array => $byKey[$key] ?? [];
    }

    /**
     * @param array<string, PublicationRefs> $byKey packages not named
     *        here have nothing published
     * @return callable(string, string): PublicationRefs
     */
    private static function refs(array $byKey): callable
    {
        return static fn (string $key): PublicationRefs => $byKey[$key] ?? new PublicationRefs(main: null, tag: null);
    }
}
