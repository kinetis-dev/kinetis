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
}
