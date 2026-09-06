<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../validate-manifest.php';

final class ValidateManifestTest extends TestCase
{
    /**
     * comparisonBase() falls back to GITHUB_EVENT_BEFORE, which a run
     * inside Actions has set for a completely different repository.
     */
    protected function setUp(): void
    {
        putenv('GITHUB_EVENT_BEFORE');
    }

    public function test_the_repository_manifest_passes_the_schema(): void
    {
        self::assertSame([], checkManifestSchema(loadManifest()));
    }

    public function test_the_fixture_manifest_passes_the_schema(): void
    {
        // Every test below asserts one named problem, which a fixture
        // carrying problems of its own would still satisfy.
        self::assertSame([], checkManifestSchema(self::manifest(), self::root()));
    }

    public function test_a_missing_required_field_is_named(): void
    {
        $manifest = self::manifest();
        unset($manifest['packages']['demo']['namespace']);

        self::assertContains("demo: 'namespace' must be a non-empty string", checkManifestSchema($manifest, self::root()));
    }

    public function test_an_unknown_package_key_is_named(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['demo']['autoloadfiles'] = [];

        self::assertContains("demo: unknown key 'autoloadfiles'", checkManifestSchema($manifest, self::root()));
    }

    public function test_a_package_key_that_is_not_a_directory_name_is_rejected(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['Demo_One'] = $manifest['packages']['demo'];

        self::assertContains(
            'Demo_One: package key must be lowercase words joined by single dashes',
            checkManifestSchema($manifest, self::root()),
        );
    }

    public function test_a_package_with_no_directory_is_named(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['absent'] = $manifest['packages']['demo'];

        self::assertContains('absent: no packages/absent directory', checkManifestSchema($manifest, self::root()));
    }

    public function test_a_version_off_the_incubation_line_is_rejected(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['demo']['version'] = '2.0.0';

        self::assertContains("demo: 'version' must be a canonical 1.x.y version", checkManifestSchema($manifest, self::root()));
    }

    public function test_a_sibling_that_is_not_a_manifest_package_is_named(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['demo']['requires'] = ['nowhere'];

        self::assertContains(
            "demo: 'requires' names 'nowhere', which is not a manifest package",
            checkManifestSchema($manifest, self::root()),
        );
    }

    public function test_a_package_requiring_itself_is_named(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['demo']['requires'] = ['demo'];

        self::assertContains("demo: 'requires' names the package itself", checkManifestSchema($manifest, self::root()));
    }

    public function test_a_require_block_that_is_not_a_constraint_map_is_rejected(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['demo']['require'] = ['amphp/sql'];

        self::assertContains(
            "demo: 'require' must be an object of package name => string",
            checkManifestSchema($manifest, self::root()),
        );
    }

    public function test_the_repository_manifest_is_acyclic(): void
    {
        self::assertNull(checkCycles(loadManifest()));
    }

    public function test_a_cycle_is_reported_with_its_path(): void
    {
        $cycle = checkCycles(['packages' => [
            'a' => ['requires' => ['b']],
            'b' => ['requires' => ['a']],
        ]]);

        self::assertSame('Cycle detected: a -> b -> a', $cycle);
    }

    public function test_the_repository_manifest_has_no_shared_dependency_drift(): void
    {
        self::assertSame([], checkVersionConsistency(loadManifest()));
    }

    public function test_two_constraints_for_one_shared_dependency_are_reported(): void
    {
        $problems = checkVersionConsistency(['packages' => [
            'a' => ['require' => ['amphp/sql' => '^2']],
            'b' => ['require' => ['amphp/sql' => '^1']],
        ]]);

        self::assertSame(['amphp/sql: ^2 (a) vs. ^1 (b)'], $problems);
    }

    public function test_a_manifest_entry_changing_without_a_bump_is_rejected(): void
    {
        $old = self::versioned('1.0.0');
        $new = self::versioned('1.0.0');
        $new['packages']['demo']['description'] = 'changed';

        self::assertSame(
            ["demo: manifest entry changed but 'version' was not bumped"],
            checkVersionBumpCompleteness($old, $new),
        );
    }

    public function test_a_one_step_bump_passes(): void
    {
        self::assertSame([], checkVersionBumpCompleteness(self::versioned('1.0.0'), self::versioned('1.0.1')));
    }

    public function test_a_skipped_version_is_rejected(): void
    {
        $problems = checkVersionBumpCompleteness(self::versioned('1.0.0'), self::versioned('1.0.2'));

        self::assertStringContainsString('demo: version jumped from 1.0.0 to 1.0.2', $problems[0]);
    }

    public function test_a_major_bump_is_rejected(): void
    {
        $problems = checkVersionBumpCompleteness(self::versioned('1.0.0'), self::versioned('2.0.0'));

        self::assertStringContainsString('leaves the 1.x line', $problems[0]);
    }

    public function test_a_brand_new_package_has_to_start_at_the_initial_version(): void
    {
        $problems = checkVersionBumpCompleteness(['packages' => []], self::versioned('1.2.0'));

        self::assertStringContainsString('a new package starts at 1.0.0', $problems[0]);
    }

    public function test_an_unchanged_package_needs_nothing(): void
    {
        self::assertSame([], checkVersionBumpCompleteness(self::versioned('1.0.0'), self::versioned('1.0.0')));
    }

    public function test_a_package_file_change_without_a_bump_is_rejected(): void
    {
        $problems = checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.0'),
            ['packages/demo/docker-compose.yml', 'packages/demo/bootstrap.php'],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString("demo: package files changed but 'version' was not bumped", $problems[0]);
        self::assertStringContainsString('docker-compose.yml', $problems[0]);
    }

    public function test_a_composer_lock_only_change_passes_without_a_bump(): void
    {
        self::assertSame([], checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.0'),
            ['packages/demo/composer.lock'],
        ));
    }

    public function test_a_nested_lock_file_is_real_content(): void
    {
        $problems = checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.0'),
            ['packages/demo/tests/fixtures/composer.lock'],
        );

        self::assertCount(1, $problems);
    }

    public function test_a_content_change_paired_with_a_bump_passes(): void
    {
        self::assertSame([], checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.1'),
            ['packages/demo/docker-compose.yml'],
        ));
    }

    public function test_a_brand_new_package_is_exempt_from_the_content_check(): void
    {
        self::assertSame([], checkContentBumpCompleteness(
            ['packages' => []],
            self::versioned('1.0.0'),
            ['packages/demo/src/NewFile.php'],
        ));
    }

    public function test_changed_files_outside_any_manifest_package_are_ignored(): void
    {
        self::assertSame([], checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.0'),
            ['packages/removed-package/old.php', 'tools/validate-manifest.php'],
        ));
    }

    /**
     * A file moved from one package to another reaches this check as two
     * paths, one under each package — changedPackagePaths() turns git's
     * rename detection off precisely so the source package is still
     * named. Both need their own bump: the source's next release drops
     * that file.
     */
    public function test_a_cross_package_move_needs_a_bump_on_both_sides(): void
    {
        $manifest = ['packages' => [
            'demo' => ['version' => '1.0.0'],
            'other' => ['version' => '1.0.0'],
        ]];

        $problems = checkContentBumpCompleteness($manifest, $manifest, [
            'packages/demo/src/Thing.php',
            'packages/other/src/Thing.php',
        ]);

        self::assertCount(2, $problems);
        self::assertStringContainsString('demo:', $problems[0]);
        self::assertStringContainsString('other:', $problems[1]);
    }

    public function test_a_within_package_rename_needs_one_bump(): void
    {
        self::assertSame([], checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.1'),
            ['packages/demo/src/Thing.php', 'packages/demo/src/Renamed.php'],
        ));
    }

    public function test_a_path_holding_spaces_and_quotes_is_attributed_to_its_package(): void
    {
        $problems = checkContentBumpCompleteness(
            self::versioned('1.0.0'),
            self::versioned('1.0.0'),
            ['packages/demo/src/a file "with" spaces.php'],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('a file "with" spaces.php', $problems[0]);
    }

    public function test_every_manifest_package_has_a_ci_and_an_infection_job(): void
    {
        $manifest = loadManifest();

        self::assertSame([], checkWorkflowCoverage(
            $manifest,
            workflowPackages(__DIR__ . '/../../.github/workflows/ci.yml'),
            workflowPackages(__DIR__ . '/../../.github/workflows/infection.yml'),
        ));
    }

    public function test_a_package_missing_from_ci_is_named(): void
    {
        $problems = checkWorkflowCoverage(['packages' => ['demo' => []]], [], ['demo']);

        self::assertSame(['demo has no job in ci.yml — add one to its matrix.'], $problems);
    }

    public function test_a_package_missing_from_infection_is_named(): void
    {
        $problems = checkWorkflowCoverage(['packages' => ['demo' => []]], ['demo'], []);

        self::assertStringContainsString('demo has no job in infection.yml', $problems[0]);
    }

    public function test_an_exempt_package_needs_no_infection_job(): void
    {
        self::assertSame([], checkWorkflowCoverage(['packages' => ['pingpong' => []]], ['pingpong'], []));
    }

    public function test_a_workflow_job_with_no_package_is_named(): void
    {
        $problems = checkWorkflowCoverage(['packages' => []], ['ghost'], ['ghost']);

        self::assertStringContainsString('ci.yml has a job for "ghost"', $problems[0]);
    }

    public function test_the_tools_directory_is_a_workflow_only_job(): void
    {
        self::assertSame([], checkWorkflowCoverage(['packages' => []], ['tools'], ['tools']));
    }

    public function test_the_sonar_coverage_loop_and_report_paths_agree(): void
    {
        self::assertSame([], checkCoverageWiring(
            coverageLoopPackages(__DIR__ . '/../../.github/workflows/sonarqube.yml'),
            coverageReportPackages(__DIR__ . '/../../sonar-project.properties'),
        ));
    }

    public function test_a_generated_report_nobody_reads_is_named(): void
    {
        $problems = checkCoverageWiring(['demo'], []);

        self::assertStringContainsString('reads as 0% covered', $problems[0]);
    }

    public function test_a_report_path_nobody_writes_is_named(): void
    {
        $problems = checkCoverageWiring([], ['demo']);

        self::assertStringContainsString('never generates', $problems[0]);
    }

    public function test_an_explicit_base_wins_over_the_environment(): void
    {
        $repository = self::scratchRepository();
        $first = self::commit($repository, ManifestFixture::json(['demo' => '1.0.0']));
        self::commit($repository, ManifestFixture::json(['demo' => '1.0.1']));

        self::assertSame($first, comparisonBase($first, $repository));
    }

    public function test_the_first_commit_has_nothing_to_compare_against(): void
    {
        $repository = self::scratchRepository();
        self::commit($repository, ManifestFixture::json());

        self::assertNull(comparisonBase(null, $repository));
    }

    public function test_the_previous_commit_is_what_a_change_is_compared_against(): void
    {
        $repository = self::scratchRepository();
        $first = self::commit($repository, ManifestFixture::json(['demo' => '1.0.0']));
        self::commit($repository, ManifestFixture::json(['demo' => '1.0.1']));

        self::assertSame($first, comparisonBase(null, $repository));
    }

    /**
     * A shallow checkout's oldest commit records no parent, exactly as a
     * root commit does. Reading it as a root commit would report both
     * history checks as skipped on a history that is absent.
     */
    public function test_a_shallow_checkout_is_not_a_first_commit(): void
    {
        $repository = self::scratchRepository();
        self::commit($repository, ManifestFixture::json(['demo' => '1.0.0']));
        self::commit($repository, ManifestFixture::json(['demo' => '1.0.1']));
        $shallow = self::scratchRepository() . '-shallow';
        run(sys_get_temp_dir(), ['git', 'clone', '--quiet', '--depth=1', "file://{$repository}", $shallow]);

        $this->expectExceptionMessage('the repository is shallow');

        comparisonBase(null, $shallow);
    }

    public function test_a_base_that_cannot_be_read_fails_rather_than_skipping(): void
    {
        $repository = self::scratchRepository();
        self::commit($repository, ManifestFixture::json());

        $this->expectExceptionMessage("Comparison base 'deadbeef' is not a commit this checkout can read");

        comparisonBase('deadbeef', $repository);
    }

    public function test_the_all_zero_sha_is_a_first_push_rather_than_a_bad_base(): void
    {
        $repository = self::scratchRepository();
        self::commit($repository, ManifestFixture::json());

        self::assertNull(comparisonBase(str_repeat('0', 40), $repository));
    }

    public function test_the_manifest_at_a_commit_is_read_back(): void
    {
        $repository = self::scratchRepository();
        $first = self::commit($repository, ManifestFixture::json(['demo' => '1.2.3']));

        self::assertSame('1.2.3', manifestAtCommit($first, $repository)['packages']['demo']['version']);
    }

    public function test_a_commit_carrying_no_manifest_fails_plainly(): void
    {
        $repository = self::scratchRepository();
        file_put_contents("{$repository}/README.md", "hello\n");
        $commit = self::commitAll($repository);

        $this->expectExceptionMessage('carries no packages.manifest.json');

        manifestAtCommit($commit, $repository);
    }

    public function test_changed_package_paths_are_listed_against_the_base(): void
    {
        $repository = self::scratchRepository();
        $first = self::commit($repository, ManifestFixture::json());
        mkdir("{$repository}/packages/demo/src", 0o755, recursive: true);
        file_put_contents("{$repository}/packages/demo/src/Thing.php", "<?php\n");
        self::commitAll($repository);

        self::assertSame(['packages/demo/src/Thing.php'], changedPackagePaths($first, $repository));
    }

    public function test_the_validator_rejects_an_unknown_option(): void
    {
        self::assertSame(['Unknown option: --nope'], parseValidatorArguments(['--nope'])['problems']);
    }

    public function test_an_empty_base_is_a_missing_base_rather_than_no_base(): void
    {
        self::assertSame(['--base needs a commit id or a ref name.'], parseValidatorArguments(['--base='])['problems']);
    }

    public function test_a_repeated_base_is_rejected(): void
    {
        self::assertContains('--base is given more than once.', parseValidatorArguments(['--base=a', '--base=b'])['problems']);
    }

    /**
     * A scratch project root holding only the package directory the
     * schema fixture names, so the directory check answers for a real
     * tree rather than this repository's own.
     */
    private static function root(): string
    {
        static $root = null;

        if ($root === null) {
            $root = sys_get_temp_dir() . '/kinetis-schema-' . bin2hex(random_bytes(6));
            mkdir("{$root}/packages/demo", 0o755, recursive: true);
        }

        return $root;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return [
            'defaults' => ManifestFixture::defaults(),
            'packages' => [
                'demo' => [
                    'name' => 'kinetis/demo',
                    'description' => 'the demo package',
                    'namespace' => 'Kinetis\\Demo\\',
                    'version' => '1.0.0',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function versioned(string $version): array
    {
        return ['packages' => ['demo' => ['name' => 'kinetis/demo', 'version' => $version]]];
    }

    private static function scratchRepository(): string
    {
        $path = sys_get_temp_dir() . '/kinetis-validate-' . bin2hex(random_bytes(6));
        mkdir($path, 0o755, recursive: true);
        run($path, ['git', 'init', '--quiet', '--initial-branch=main']);
        run($path, ['git', 'config', 'user.name', 'Test']);
        run($path, ['git', 'config', 'user.email', 'test@example.com']);

        return $path;
    }

    private static function commit(string $repository, string $manifestJson): string
    {
        file_put_contents("{$repository}/packages.manifest.json", $manifestJson);

        return self::commitAll($repository);
    }

    private static function commitAll(string $repository): string
    {
        run($repository, ['git', 'add', '-A']);
        run($repository, ['git', 'commit', '--quiet', '--allow-empty', '-m', 'change']);

        return trim((string) git($repository, 'rev-parse', 'HEAD'));
    }
}
