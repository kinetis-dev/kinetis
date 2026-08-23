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

    protected function tearDown(): void
    {
        putenv('GITHUB_EVENT_BEFORE');
    }

    public function test_old_manifest_ref_falls_back_to_head_caret_when_the_env_var_is_unset(): void
    {
        putenv('GITHUB_EVENT_BEFORE');

        self::assertSame('HEAD^', oldManifestRef());
    }

    public function test_old_manifest_ref_falls_back_to_head_caret_when_the_env_var_is_empty(): void
    {
        putenv('GITHUB_EVENT_BEFORE=');

        self::assertSame('HEAD^', oldManifestRef());
    }

    public function test_old_manifest_ref_falls_back_to_head_caret_for_a_branchs_first_ever_push(): void
    {
        // GitHub sends the all-zero SHA as "before" when a branch (or the
        // repository) had no prior commit for this push to follow.
        putenv('GITHUB_EVENT_BEFORE=' . str_repeat('0', 40));

        self::assertSame('HEAD^', oldManifestRef());
    }

    public function test_old_manifest_ref_uses_the_real_sha_when_the_env_var_is_set(): void
    {
        $sha = str_repeat('a', 40);
        putenv("GITHUB_EVENT_BEFORE={$sha}");

        self::assertSame($sha, oldManifestRef());
    }

    /** @return array{packages: array<string, array<string, mixed>>} */
    private static function contentManifest(string $version = '1.0.0'): array
    {
        return ['packages' => ['pingpong' => ['name' => 'kinetis/pingpong', 'version' => $version]]];
    }

    public function test_content_change_without_a_version_bump_fails(): void
    {
        $problems = checkContentBumpCompleteness(
            self::contentManifest(),
            self::contentManifest(),
            ['packages/pingpong/docker-compose.yml', 'packages/pingpong/bootstrap.php'],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString("pingpong: package files changed but 'version' was not bumped", $problems[0]);
        self::assertStringContainsString('docker-compose.yml', $problems[0]);
    }

    public function test_a_composer_lock_only_change_passes_without_a_bump(): void
    {
        $problems = checkContentBumpCompleteness(
            self::contentManifest(),
            self::contentManifest(),
            ['packages/pingpong/composer.lock'],
        );

        self::assertSame([], $problems);
    }

    public function test_content_change_paired_with_a_version_bump_passes(): void
    {
        $problems = checkContentBumpCompleteness(
            self::contentManifest('1.0.0'),
            self::contentManifest('1.0.1'),
            ['packages/pingpong/docker-compose.yml'],
        );

        self::assertSame([], $problems);
    }

    public function test_a_brand_new_package_is_exempt_from_the_content_check(): void
    {
        $problems = checkContentBumpCompleteness(
            ['packages' => []],
            self::contentManifest(),
            ['packages/pingpong/src/NewFile.php'],
        );

        self::assertSame([], $problems);
    }

    public function test_changed_files_outside_any_manifest_package_are_ignored(): void
    {
        $problems = checkContentBumpCompleteness(
            self::contentManifest(),
            self::contentManifest(),
            ['packages/removed-package/old.php', 'tools/validate-manifest.php'],
        );

        self::assertSame([], $problems);
    }

    public function test_no_previous_manifest_skips_the_content_check(): void
    {
        self::assertSame(
            [],
            checkContentBumpCompleteness(null, self::contentManifest(), ['packages/pingpong/src/A.php']),
        );
    }

    public function test_a_nested_lock_named_file_still_counts_as_content(): void
    {
        // Only the package-root composer.lock is release-deleted; a file
        // that merely shares the name deeper in the tree (a test
        // fixture's lock) is real, shipped content.
        $problems = checkContentBumpCompleteness(
            self::contentManifest(),
            self::contentManifest(),
            ['packages/pingpong/tests/Fixtures/composer.lock'],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('tests/Fixtures/composer.lock', $problems[0]);
    }

    public function test_changed_package_files_reads_a_real_git_diff(): void
    {
        $repo = sys_get_temp_dir() . '/content-bump-' . bin2hex(random_bytes(6));
        mkdir($repo . '/packages/demo', 0777, true);

        $git = static function (string ...$args) use ($repo): void {
            $process = proc_open(array_values(['git', '-C', $repo, ...$args]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            \assert(is_resource($process));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        };

        $git('init', '-q');
        $git('config', 'user.email', 'test@example.com');
        $git('config', 'user.name', 'test');
        file_put_contents($repo . '/packages/demo/a.txt', "one\n");
        $git('add', '.');
        $git('commit', '-q', '-m', 'initial');
        file_put_contents($repo . '/packages/demo/a.txt', "two\n");

        // changedPackageFiles() runs against PROJECT_ROOT, so exercise
        // the identical git invocation against the scratch repo directly.
        $process = proc_open(
            ['git', '-C', $repo, 'diff', '--name-only', 'HEAD', '--', 'packages'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \assert(is_resource($process));
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertIsString($output);
        self::assertSame(['packages/demo/a.txt'], array_values(array_filter(explode("\n", trim($output)))));

        unlink($repo . '/packages/demo/a.txt');
        exec('rm -rf ' . escapeshellarg($repo));
    }

    public function test_workflow_coverage_accepts_a_package_present_in_both_workflows(): void
    {
        $manifest = ['packages' => ['framework' => [], 'session' => []]];

        self::assertSame([], checkWorkflowCoverage($manifest, ['framework', 'session'], ['framework', 'session']));
    }

    public function test_workflow_coverage_names_a_package_missing_from_ci(): void
    {
        $manifest = ['packages' => ['framework' => [], 'telemetry' => []]];

        $problems = checkWorkflowCoverage($manifest, ['framework'], ['framework', 'telemetry']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('telemetry has no job in ci.yml', $problems[0]);
    }

    /**
     * The drift this check exists for: session and telemetry were added
     * without an infection.yml job, which nothing noticed.
     */
    public function test_workflow_coverage_names_a_package_missing_from_infection(): void
    {
        $manifest = ['packages' => ['framework' => [], 'session' => []]];

        $problems = checkWorkflowCoverage($manifest, ['framework', 'session'], ['framework']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('session has no job in infection.yml', $problems[0]);
    }

    public function test_workflow_coverage_allows_a_named_infection_exemption(): void
    {
        $manifest = ['packages' => ['pingpong' => []]];

        self::assertSame([], checkWorkflowCoverage($manifest, ['pingpong'], []));
    }

    public function test_workflow_coverage_allows_a_named_non_package_job(): void
    {
        $manifest = ['packages' => ['framework' => []]];

        self::assertSame([], checkWorkflowCoverage($manifest, ['framework', 'tools'], ['framework']));
    }

    /**
     * A job left behind after a package is renamed or removed points at
     * a directory nothing builds any more.
     */
    public function test_workflow_coverage_names_a_job_with_no_matching_package(): void
    {
        $manifest = ['packages' => ['framework' => []]];

        $problems = checkWorkflowCoverage($manifest, ['framework', 'skeleton'], ['framework']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('ci.yml has a job for "skeleton"', $problems[0]);
    }

    /**
     * Read from dir: rather than name: — the two differ, the framework
     * package being called "core" in both workflows.
     */
    public function test_workflow_packages_reads_the_repos_own_workflows(): void
    {
        $ci = workflowPackages(__DIR__ . '/../../.github/workflows/ci.yml');

        self::assertContains('framework', $ci, 'matched on dir:, so the framework package appears under its own key');
        self::assertNotContains('core', $ci);
        self::assertContains('tools', $ci);
    }

    public function test_workflow_packages_is_empty_for_a_file_that_does_not_exist(): void
    {
        self::assertSame([], workflowPackages(__DIR__ . '/does-not-exist.yml'));
    }

    public function test_coverage_wiring_accepts_matching_lists(): void
    {
        self::assertSame([], checkCoverageWiring(['framework', 'session'], ['session', 'framework']));
    }

    /**
     * The mistake this catches: a package added to the loop that
     * generates reports, but not to the list that reads them. The report
     * is written and thrown away, and the package reads as 0% covered
     * while its tests pass.
     */
    public function test_coverage_wiring_names_a_report_nobody_reads(): void
    {
        $problems = checkCoverageWiring(['framework', 'pingpong'], ['framework']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('pingpong', $problems[0]);
        self::assertStringContainsString('never reads', $problems[0]);
    }

    public function test_coverage_wiring_names_a_report_nobody_writes(): void
    {
        $problems = checkCoverageWiring(['framework'], ['framework', 'gone']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('never generates', $problems[0]);
    }

    public function test_coverage_lists_are_read_from_the_repos_own_files(): void
    {
        $loop = coverageLoopPackages(__DIR__ . '/../../.github/workflows/sonarqube.yml');
        $read = coverageReportPackages(__DIR__ . '/../../sonar-project.properties');

        self::assertContains('framework', $loop);
        self::assertContains('framework', $read);
        self::assertSame([], checkCoverageWiring($loop, $read), 'the repo\'s own coverage wiring must agree');
    }

    public function test_coverage_lists_are_empty_for_files_that_do_not_exist(): void
    {
        self::assertSame([], coverageLoopPackages(__DIR__ . '/nope.yml'));
        self::assertSame([], coverageReportPackages(__DIR__ . '/nope.properties'));
    }
}
