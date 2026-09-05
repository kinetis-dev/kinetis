<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicationRefs;
use ReleasePlanFailure;

require_once __DIR__ . '/../release-plan.php';

/** Two distinct object ids, so a test never asserts a value against itself. */
const SHA_ONE = '9f1c0a0e1f3b7a2d4c5e6f708192a3b4c5d6e7f8';

/** @see SHA_ONE */
const SHA_TWO = '1a2b3c4d5e6f708192a3b4c5d6e7f89f1c0a0e1f';

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

    /** Everything published: tag and main both at the same commit. */
    private function published(string $commit): PublicationRefs
    {
        return new PublicationRefs($commit, null, $commit);
    }

    /**
     * @param array<string, PublicationRefs> $byRepo
     * @return callable(string, string): PublicationRefs
     */
    private function refsReturning(array $byRepo): callable
    {
        return static fn (string $repo, string $tag): PublicationRefs
            => $byRepo[$repo] ?? new PublicationRefs(null, null, null);
    }

    public function test_finds_unpublished_candidates_even_with_zero_manifest_diff(): void
    {
        // The scenario this exists for: a version already sitting in the
        // manifest, unchanged for many commits, that has genuinely never
        // been published — findReleaseCandidates() alone would never
        // catch this, since there's nothing to diff.
        $manifest = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
        ]];

        $candidates = findUnpublishedCandidates($manifest, $this->refsReturning([]));

        self::assertSame(['a', 'b'], $candidates);
    }

    public function test_excludes_a_package_whose_current_version_is_fully_published(): void
    {
        $manifest = ['packages' => [
            'a' => ['version' => '1.0.0'],
            'b' => ['version' => '1.0.0'],
        ]];

        $candidates = findUnpublishedCandidates($manifest, $this->refsReturning([
            'a' => $this->published(SHA_ONE),
        ]));

        self::assertSame(['b'], $candidates);
    }

    /**
     * The half-published round: the tag landed, the branch did not. The
     * package has to stay a candidate, or nothing ever repairs main.
     */
    public function test_a_tagged_package_whose_main_is_stale_is_still_a_candidate(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        $candidates = findUnpublishedCandidates($manifest, $this->refsReturning([
            'a' => new PublicationRefs(SHA_ONE, null, SHA_TWO),
        ]));

        self::assertSame(['a'], $candidates);
    }

    public function test_a_tagged_package_with_no_main_branch_at_all_is_still_a_candidate(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        $candidates = findUnpublishedCandidates($manifest, $this->refsReturning([
            'a' => new PublicationRefs(SHA_ONE, null, null),
        ]));

        self::assertSame(['a'], $candidates);
    }

    /** An annotated tag is compared by what it peels to, not by its own object. */
    public function test_an_annotated_tag_whose_peeled_commit_is_on_main_is_published(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        $candidates = findUnpublishedCandidates($manifest, $this->refsReturning([
            'a' => new PublicationRefs(SHA_TWO, SHA_ONE, SHA_ONE),
        ]));

        self::assertSame([], $candidates);
    }

    public function test_the_unpublished_check_asks_for_the_exact_v_prefixed_tag(): void
    {
        $manifest = ['packages' => ['a' => ['version' => '2.3.1']]];

        $seen = [];
        findUnpublishedCandidates($manifest, function (string $repo, string $tag) use (&$seen): PublicationRefs {
            $seen[] = [$repo, $tag];

            return $this->published(SHA_ONE);
        });

        self::assertSame([['a', 'v2.3.1']], $seen);
    }

    public function test_a_version_with_no_published_state_stops_being_a_candidate_once_it_has_one(): void
    {
        // Confirms the "self-healing, no bookkeeping needed" claim in
        // findUnpublishedCandidates()'s own doc comment directly: the
        // same package and version, checked twice, comes back candidate
        // then clean purely because the injected remote state changed —
        // nothing about the package itself had to be told it's released.
        $manifest = ['packages' => ['a' => ['version' => '1.0.0']]];

        self::assertSame(['a'], findUnpublishedCandidates($manifest, $this->refsReturning([])));
        self::assertSame([], findUnpublishedCandidates($manifest, $this->refsReturning([
            'a' => $this->published(SHA_ONE),
        ])));
    }

    /**
     * A re-run of a partially-successful round replays the same diff, so
     * an already-published package is still a diff-based candidate. It
     * stays in the union because the publication transaction is the only
     * place that can tell a finished package from one whose main branch
     * never caught up, and it needs to see both.
     */
    public function test_a_diff_based_candidate_already_published_still_reaches_the_transaction(): void
    {
        $candidates = resolveCandidates(diffCandidates: ['a', 'b'], unpublishedCandidates: []);

        self::assertSame(['a', 'b'], $candidates);
    }

    public function test_a_candidate_found_by_both_sources_appears_exactly_once(): void
    {
        self::assertSame(['a'], resolveCandidates(diffCandidates: ['a'], unpublishedCandidates: ['a']));
    }

    public function test_both_sources_contribute_to_the_union(): void
    {
        self::assertSame(['a', 'b'], resolveCandidates(diffCandidates: ['a'], unpublishedCandidates: ['b']));
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

    /**
     * The failure this proof exists for. Kahn's algorithm can order
     * nothing at all in a cycle, and filtering that empty order down to
     * the candidates reads as a clean "nothing to release": the two
     * packages whose versions changed vanish from the plan,
     * and release.yml publishes neither while going green.
     */
    public function test_a_cycle_fails_the_plan_instead_of_emptying_it(): void
    {
        $manifest = ['packages' => [
            'a' => ['requires' => ['b'], 'version' => '1.0.0'],
            'b' => ['requires' => ['a'], 'version' => '1.0.0'],
        ]];

        self::assertSame([], topologicalOrder(buildGraph($manifest['packages'])), 'the precondition: nothing orders');

        try {
            publishOrder($manifest, ['a', 'b']);
            self::fail('a cyclic graph must fail the plan');
        } catch (ReleasePlanFailure $e) {
            self::assertStringContainsString('no publish order', $e->getMessage());
        }
    }

    public function test_a_cycle_among_packages_that_are_not_candidates_still_fails_the_plan(): void
    {
        // The order is computed over the whole graph, so a cycle
        // anywhere in it means no candidate's position can be trusted.
        $manifest = ['packages' => [
            'a' => ['requires' => [], 'version' => '1.0.0'],
            'b' => ['requires' => ['c'], 'version' => '1.0.0'],
            'c' => ['requires' => ['b'], 'version' => '1.0.0'],
        ]];

        $this->expectException(ReleasePlanFailure::class);

        publishOrder($manifest, ['a']);
    }

    public function test_a_dependency_on_a_package_that_does_not_exist_fails_the_plan(): void
    {
        $manifest = ['packages' => [
            'a' => ['requires' => ['ghost'], 'version' => '1.0.0'],
        ]];

        try {
            publishOrder($manifest, ['a']);
            self::fail('an unknown dependency must fail the plan');
        } catch (ReleasePlanFailure $e) {
            self::assertStringContainsString('a requires ghost', $e->getMessage());
        }
    }

    public function test_a_candidate_that_is_not_a_manifest_package_fails_the_plan(): void
    {
        $manifest = ['packages' => ['a' => ['requires' => [], 'version' => '1.0.0']]];

        try {
            publishOrder($manifest, ['a', 'ghost']);
            self::fail('a candidate outside the manifest must fail the plan');
        } catch (ReleasePlanFailure $e) {
            self::assertStringContainsString('ghost is not a package', $e->getMessage());
        }
    }

    public function test_a_valid_graph_orders_every_candidate_it_was_given(): void
    {
        $manifest = ['packages' => [
            'framework' => ['requires' => [], 'version' => '1.0.0'],
            'persistence' => ['requires' => ['framework'], 'version' => '1.0.0'],
            'queue' => ['requires' => ['framework', 'persistence'], 'version' => '1.0.0'],
        ]];

        $order = publishOrder($manifest, ['queue', 'framework', 'persistence']);

        self::assertSame(['framework', 'persistence', 'queue'], $order);
    }

    public function test_check_resolution_reports_a_problem_for_each_sibling_whose_tag_is_missing(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => ['kinetis']],
            'persistence' => ['version' => '1.4.0'],
            'kinetis' => ['version' => '2.0.0'],
        ]];

        $problems = checkResolution($manifest, 'queue', candidateSet: [], refsFor: $this->refsReturning([]));

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

        $problems = checkResolution($manifest, 'queue', candidateSet: [], refsFor: static fn (string $repo, string $tag): PublicationRefs => new PublicationRefs(SHA_ONE, null, SHA_ONE));

        self::assertSame([], $problems);
    }

    public function test_check_resolution_asks_for_the_exact_v_prefixed_tag(): void
    {
        $manifest = ['packages' => [
            'queue' => ['requires' => ['persistence'], 'requiresDev' => []],
            'persistence' => ['version' => '1.4.0'],
        ]];

        $seen = [];
        checkResolution($manifest, 'queue', candidateSet: [], refsFor: function (string $repo, string $tag) use (&$seen): PublicationRefs {
            $seen[] = [$repo, $tag];

            return $this->published(SHA_ONE);
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
            refsFor: $this->refsReturning([]),
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
            refsFor: function (string $repo, string $tag) use (&$called): PublicationRefs {
                $called = true;

                return new PublicationRefs(null, null, null);
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
            refsFor: function (string $repo, string $tag) use (&$seen): PublicationRefs {
                $seen[] = [$repo, $tag];

                return $this->published(SHA_ONE);
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
     * @param array{exitCode?: int, stdout?: string, stderr?: string, timedOut?: bool, truncated?: bool} $result
     * @return callable(list<string>): array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
     */
    private function gitReturning(array $result): callable
    {
        return static fn (array $args): array => [
            'exitCode' => $result['exitCode'] ?? 0,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'timedOut' => $result['timedOut'] ?? false,
            'truncated' => $result['truncated'] ?? false,
        ];
    }

    public function test_a_matching_ref_comes_back_as_its_object_id(): void
    {
        $records = refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning([
            'stdout' => SHA_ONE . "\trefs/tags/v1.2.3\n",
        ]));

        self::assertSame(['refs/tags/v1.2.3' => SHA_ONE], $records);
    }

    public function test_a_successful_lookup_with_no_matching_ref_means_the_refs_are_absent(): void
    {
        self::assertSame([], refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning([])));
    }

    public function test_the_lookup_asks_the_split_repo_for_the_exact_refs(): void
    {
        $seen = [];
        publicationRefs('queue', 'v1.2.3', function (array $args) use (&$seen): array {
            $seen = $args;

            return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'truncated' => false];
        });

        self::assertContains('https://github.com/kinetis-dev/queue.git', $seen);
        self::assertContains('refs/tags/v1.2.3', $seen);
        self::assertContains('refs/heads/main', $seen);
        self::assertContains('--end-of-options', $seen);
    }

    /**
     * Absence has to mean one thing. A remote that refuses the
     * connection is not evidence that a ref is missing, and reading it
     * that way makes every package a candidate and republishes work that
     * already exists.
     *
     * @return iterable<string, array{array{exitCode?: int, stdout?: string, stderr?: string, timedOut?: bool, truncated?: bool}, string}>
     */
    public static function lookupFailures(): iterable
    {
        yield 'git could not start' => [
            ['exitCode' => -1, 'stderr' => 'git could not be started'],
            'Could not reach',
        ];
        yield 'the repository is missing' => [
            ['exitCode' => 128, 'stderr' => "remote: Repository not found.\nfatal: repository not found"],
            'Could not reach',
        ];
        yield 'authentication was refused' => [
            ['exitCode' => 128, 'stderr' => 'fatal: Authentication failed'],
            'Could not reach',
        ];
        yield 'the network is unreachable' => [
            ['exitCode' => 128, 'stderr' => 'fatal: unable to access: Could not resolve host'],
            'Could not reach',
        ];
        yield 'the read stalled' => [
            ['exitCode' => -1, 'timedOut' => true, 'stderr' => 'git did not finish within 30s'],
            'did not finish in time',
        ];
    }

    /** @param array{exitCode?: int, stdout?: string, stderr?: string, timedOut?: bool, truncated?: bool} $result */
    #[DataProvider('lookupFailures')]
    public function test_a_lookup_that_establishes_nothing_aborts_rather_than_reporting_absence(
        array $result,
        string $expected,
    ): void {
        try {
            refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning($result));
            self::fail('a failed lookup must not answer the question');
        } catch (ReleasePlanFailure $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertStringContainsString('kinetis-dev/queue', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function indeterminateLookupOutput(): iterable
    {
        yield 'a different tag' => [SHA_ONE . "\trefs/tags/v9.9.9\n"];
        yield 'a tag the wanted one is a prefix of' => [SHA_ONE . "\trefs/tags/v1.2.30\n"];
        yield 'a branch that was not asked for' => [SHA_ONE . "\trefs/heads/trunk\n"];
        yield 'a warning' => ["warning: redirecting to somewhere else\n"];
        yield 'a record with no object id' => ["notasha\trefs/tags/v1.2.3\n"];
        yield 'a ref with no object id' => ["refs/tags/v1.2.3\n"];
        yield 'html' => ["<html><body>login</body></html>\n"];
    }

    /**
     * Output that names none of the asked-for refs answers nothing.
     * Reading it as absence would republish work that already exists;
     * reading it as presence would skip work that does not.
     */
    #[DataProvider('indeterminateLookupOutput')]
    public function test_output_that_names_no_matching_ref_leaves_the_lookup_indeterminate(string $stdout): void
    {
        try {
            refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning(['stdout' => $stdout]));
            self::fail('output naming no matching ref must not answer the question');
        } catch (ReleasePlanFailure $e) {
            self::assertStringContainsString('names no such ref', $e->getMessage());
        }
    }

    public function test_the_peeled_record_of_an_annotated_tag_is_reported_alongside_the_tag_object(): void
    {
        $refs = publicationRefs('queue', 'v1.2.3', $this->gitReturning([
            'stdout' => SHA_TWO . "\trefs/tags/v1.2.3\n" . SHA_ONE . "\trefs/tags/v1.2.3^{}\n",
        ]));

        self::assertSame(SHA_TWO, $refs->tag);
        self::assertSame(SHA_ONE, $refs->peeledTag);
        self::assertSame(SHA_ONE, $refs->taggedCommit());
    }

    public function test_a_lightweight_tag_names_its_own_commit(): void
    {
        $refs = publicationRefs('queue', 'v1.2.3', $this->gitReturning([
            'stdout' => SHA_ONE . "\trefs/tags/v1.2.3\n",
        ]));

        self::assertSame(SHA_ONE, $refs->taggedCommit());
        self::assertNull($refs->main);
    }

    public function test_a_matching_record_among_other_lines_still_reports_its_object_id(): void
    {
        $records = refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning([
            'stdout' => SHA_TWO . "\trefs/tags/v9.9.9\n" . SHA_ONE . "\trefs/tags/v1.2.3\n",
        ]));

        self::assertSame(['refs/tags/v1.2.3' => SHA_ONE], $records);
    }

    public function test_both_asked_for_refs_come_back_with_their_own_object_ids(): void
    {
        $refs = publicationRefs('queue', 'v1.2.3', $this->gitReturning([
            'stdout' => SHA_ONE . "\trefs/tags/v1.2.3\n" . SHA_TWO . "\trefs/heads/main\n",
        ]));

        self::assertSame(SHA_ONE, $refs->tag);
        self::assertSame(SHA_TWO, $refs->main);
    }

    /** A repository with a branch and no tag yet is a normal first-release state. */
    public function test_a_main_branch_with_no_tag_reports_the_tag_absent(): void
    {
        $refs = publicationRefs('queue', 'v1.2.3', $this->gitReturning([
            'stdout' => SHA_TWO . "\trefs/heads/main\n",
        ]));

        self::assertNull($refs->tag);
        self::assertNull($refs->taggedCommit());
        self::assertSame(SHA_TWO, $refs->main);
    }

    /** @return iterable<string, array{string}> */
    public static function emptyLookupOutput(): iterable
    {
        yield 'nothing' => [''];
        yield 'a newline' => ["\n"];
        yield 'whitespace' => ["  \n\n"];
    }

    #[DataProvider('emptyLookupOutput')]
    public function test_only_an_empty_successful_lookup_reports_the_refs_absent(string $stdout): void
    {
        $refs = publicationRefs('queue', 'v1.2.3', $this->gitReturning(['stdout' => $stdout]));

        self::assertNull($refs->tag);
        self::assertNull($refs->main);
    }

    public function test_a_lookup_failure_does_not_repeat_the_token_it_was_given(): void
    {
        try {
            refLookup('queue', ['refs/tags/v1.2.3'], $this->gitReturning([
                'exitCode' => 128,
                'stderr' => 'fatal: could not read from '
                    . 'https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz@github.com/kinetis-dev/queue.git',
            ]));
            self::fail('a failed lookup must throw');
        } catch (ReleasePlanFailure $e) {
            self::assertStringNotContainsString('ghp_abcdefghijklmnopqrstuvwxyz', $e->getMessage());
            self::assertStringContainsString('***@github.com', $e->getMessage());
        }
    }

    /**
     * Candidate discovery and sibling resolution ask about the same
     * repo/tag pairs, and each question is a network round trip.
     */
    public function test_the_same_repo_and_tag_are_looked_up_once_per_run(): void
    {
        $calls = 0;
        $refsFor = memoizeRefLookups(function (string $repo, string $tag) use (&$calls): PublicationRefs {
            $calls++;

            return $repo === 'framework' ? $this->published(SHA_ONE) : new PublicationRefs(null, null, null);
        });

        self::assertSame(SHA_ONE, $refsFor('framework', 'v1.0.0')->tag);
        self::assertSame(SHA_ONE, $refsFor('framework', 'v1.0.0')->tag);
        self::assertNull($refsFor('queue', 'v1.0.0')->tag);
        self::assertNull($refsFor('queue', 'v1.0.0')->tag);
        self::assertSame(2, $calls);
    }

    public function test_different_tags_on_one_repo_are_still_asked_separately(): void
    {
        $seen = [];
        $refsFor = memoizeRefLookups(function (string $repo, string $tag) use (&$seen): PublicationRefs {
            $seen[] = $tag;

            return new PublicationRefs(null, null, null);
        });

        $refsFor('queue', 'v1.0.0');
        $refsFor('queue', 'v1.0.1');

        self::assertSame(['v1.0.0', 'v1.0.1'], $seen);
    }

    public function test_a_lookup_failure_propagates_through_the_memo_rather_than_being_cached_as_absence(): void
    {
        $refsFor = memoizeRefLookups(
            static fn (string $repo, string $tag): PublicationRefs => throw new ReleasePlanFailure('the remote refused'),
        );

        $this->expectException(ReleasePlanFailure::class);

        $refsFor('queue', 'v1.0.0');
    }

    /**
     * A requires sibling that is also a candidate is ordered earlier by
     * publishOrder(), which proves its result covers every candidate, so
     * it is resolved by the time the dependent's turn comes.
     */
    public function test_a_same_round_requires_sibling_is_ordered_before_the_package_that_needs_it(): void
    {
        $manifest = ['packages' => [
            'framework' => ['requires' => [], 'version' => '1.0.0'],
            'queue' => ['requires' => ['framework'], 'version' => '1.0.0'],
        ]];

        self::assertSame(['framework', 'queue'], publishOrder($manifest, ['queue', 'framework']));
        self::assertSame([], checkResolution(
            $manifest,
            'queue',
            candidateSet: ['framework' => true, 'queue' => true],
            refsFor: $this->refsReturning([]),
        ));
    }

    /**
     * A re-run after a mid-round failure: the sibling the first attempt
     * published has dropped out of the candidate set, so it is checked
     * against a real tag rather than assumed.
     */
    public function test_a_sibling_published_by_an_earlier_attempt_is_checked_against_its_real_tag(): void
    {
        $manifest = ['packages' => [
            'framework' => ['requires' => [], 'version' => '1.0.0'],
            'queue' => ['requires' => ['framework'], 'version' => '1.0.0'],
        ]];

        $seen = [];
        $problems = checkResolution($manifest, 'queue', candidateSet: ['queue' => true], refsFor: function (string $repo, string $tag) use (&$seen): PublicationRefs {
            $seen[] = [$repo, $tag];

            return $this->published(SHA_ONE);
        });

        self::assertSame([['framework', 'v1.0.0']], $seen);
        self::assertSame([], $problems);
    }

    public function test_a_sibling_that_no_attempt_published_is_reported_unresolved(): void
    {
        $manifest = ['packages' => [
            'framework' => ['requires' => [], 'version' => '1.0.0'],
            'queue' => ['requires' => ['framework'], 'version' => '1.0.0'],
        ]];

        $problems = checkResolution(
            $manifest,
            'queue',
            candidateSet: ['queue' => true],
            refsFor: $this->refsReturning([]),
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('queue requires framework (v1.0.0)', $problems[0]);
    }

    /**
     * The dev graph has cycles, so no total order over it exists and
     * publishOrder() does not attempt one. A same-round dev sibling is
     * skipped because a dev dependency is absent from what a consumer
     * installs, not because anything orders it first.
     */
    public function test_the_dev_graph_the_dev_skip_covers_has_no_total_order_to_rely_on(): void
    {
        $devGraph = [
            'framework' => [],
            'persistence' => ['framework', 'queue'],
            'queue' => ['framework', 'persistence'],
        ];

        self::assertNotSame(
            count($devGraph),
            count(topologicalOrder($devGraph)),
            'requires-plus-dev edges cannot be ordered, which is why only requires edges are',
        );
    }

    public function test_publish_order_ignores_dev_edges_so_a_dev_cycle_does_not_block_a_release(): void
    {
        $manifest = ['packages' => [
            'framework' => ['requires' => [], 'version' => '1.0.0'],
            'persistence' => ['requires' => ['framework'], 'requiresDev' => ['queue'], 'version' => '1.0.0'],
            'queue' => ['requires' => ['framework'], 'requiresDev' => ['persistence'], 'version' => '1.0.0'],
        ]];

        $order = publishOrder($manifest, ['framework', 'persistence', 'queue']);

        self::assertSame(['framework', 'persistence', 'queue'], $order);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidPlanInvocations(): iterable
    {
        yield 'an unknown option' => [['--dry-run'], 'Unknown option: --dry-run'];
        yield 'an empty base' => [['--base='], '--base needs a commit id or a ref name.'];
        yield 'a whitespace base' => [['--base=   '], '--base needs a commit id or a ref name.'];
        yield 'a repeated base' => [['--base=main', '--base=other'], '--base is given more than once.'];
        yield 'a repeated json flag' => [['--json', '--json'], '--json is given more than once.'];
    }

    /**
     * An empty --base read as "no override" would compare against HEAD^
     * instead — a different question than the one asked, answered
     * silently.
     *
     * @param list<string> $args
     */
    #[DataProvider('invalidPlanInvocations')]
    public function test_an_invalid_plan_invocation_is_refused(array $args, string $expected): void
    {
        $problems = parsePlanArguments($args)['problems'];

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' | ', $problems));
    }

    public function test_a_valid_plan_invocation_carries_its_base_through(): void
    {
        $parsed = parsePlanArguments(['--json', '--base=origin/main']);

        self::assertSame([], $parsed['problems']);
        self::assertTrue($parsed['json']);
        self::assertSame('origin/main', $parsed['base']);
    }

    /**
     * The whole invocation is judged before the manifest is read, so a
     * bad argument cannot reach a remote lookup or the split loop.
     */
    public function test_an_invalid_invocation_stops_the_run_before_it_reads_anything(): void
    {
        $exitCode = main(['tools/release-plan.php', '--base=']);

        self::assertSame(1, $exitCode);
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
        self::assertStringContainsString('already published', $output);
    }
}
