<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../validate-manifest.php';

final class ValidateManifestTest extends TestCase
{
    public function test_detects_a_real_cycle_and_names_the_chain(): void
    {
        $manifest = ['packages' => [
            'a' => ['requires' => ['b']],
            'b' => ['requires' => ['c']],
            'c' => ['requires' => ['a']],
        ]];

        $result = checkCycles($manifest);

        self::assertNotNull($result);
        self::assertStringContainsString('a -> b -> c -> a', $result);
    }

    public function test_an_acyclic_graph_passes_clean(): void
    {
        $manifest = ['packages' => [
            'a' => ['requires' => ['b']],
            'b' => ['requires' => []],
        ]];

        self::assertNull(checkCycles($manifest));
    }

    public function test_detects_a_shared_external_dependency_with_conflicting_constraints(): void
    {
        $manifest = ['packages' => [
            'x' => ['require' => ['foo/bar' => '^1.0']],
            'y' => ['require' => ['foo/bar' => '^2.0']],
        ]];

        $problems = checkVersionConsistency($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('foo/bar', $problems[0]);
    }

    public function test_consistent_shared_versions_pass_clean(): void
    {
        $manifest = ['packages' => [
            'x' => ['require' => ['foo/bar' => '^1.0']],
            'y' => ['require' => ['foo/bar' => '^1.0']],
        ]];

        self::assertSame([], checkVersionConsistency($manifest));
    }

    public function test_allow_version_drift_suppresses_the_conflict(): void
    {
        $manifest = ['packages' => [
            'x' => ['require' => ['foo/bar' => '^1.0']],
            'y' => ['require' => ['foo/bar' => '^2.0'], 'allowVersionDrift' => true, 'driftReason' => 'testing'],
        ]];

        self::assertSame([], checkVersionConsistency($manifest));
    }

    public function test_a_field_changed_without_a_version_bump_fails(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];
        $new = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^2.0']]]];

        $problems = checkVersionBumpCompleteness($old, $new);

        self::assertCount(1, $problems);
        self::assertStringContainsString("'version' was not bumped", $problems[0]);
    }

    public function test_a_version_only_bump_with_nothing_else_changed_passes(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];
        $new = ['packages' => ['x' => ['version' => '1.1.0', 'require' => ['foo/bar' => '^1.0']]]];

        self::assertSame([], checkVersionBumpCompleteness($old, $new));
    }

    public function test_a_field_change_paired_with_a_version_bump_passes(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];
        $new = ['packages' => ['x' => ['version' => '2.0.0', 'require' => ['foo/bar' => '^2.0']]]];

        self::assertSame([], checkVersionBumpCompleteness($old, $new));
    }

    public function test_a_version_decrease_fails_even_with_no_other_change(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => []]]];
        $new = ['packages' => ['x' => ['version' => '0.9.0', 'require' => []]]];

        $problems = checkVersionBumpCompleteness($old, $new);

        self::assertCount(1, $problems);
        self::assertStringContainsString('must strictly increase', $problems[0]);
    }

    public function test_nothing_changed_at_all_passes(): void
    {
        $manifest = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];

        self::assertSame([], checkVersionBumpCompleteness($manifest, $manifest));
    }

    public function test_an_invalid_semver_shape_fails(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => []]]];
        $new = ['packages' => ['x' => ['version' => '1.1', 'require' => []]]];

        $problems = checkVersionBumpCompleteness($old, $new);

        self::assertCount(1, $problems);
        self::assertStringContainsString('not valid SemVer', $problems[0]);
    }

    public function test_a_brand_new_package_with_no_prior_manifest_entry_passes(): void
    {
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => []]]];
        $new = ['packages' => [
            'x' => ['version' => '1.0.0', 'require' => []],
            'y' => ['version' => '1.0.0', 'require' => []],
        ]];

        self::assertSame([], checkVersionBumpCompleteness($old, $new));
    }

    public function test_no_previous_manifest_at_all_skips_every_package_cleanly(): void
    {
        $new = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];

        self::assertSame([], checkVersionBumpCompleteness(null, $new));
    }

    public function test_identical_content_built_via_a_different_array_construction_order_still_passes(): void
    {
        // Confirms the comparison is content-based, not sensitive to how
        // the two arrays happen to have been constructed in memory.
        $old = ['packages' => ['x' => ['version' => '1.0.0', 'require' => ['foo/bar' => '^1.0']]]];
        $new = ['packages' => ['x' => ['require' => ['foo/bar' => '^1.0'], 'version' => '1.0.0']]];

        self::assertSame([], checkVersionBumpCompleteness($old, $new));
    }
}
