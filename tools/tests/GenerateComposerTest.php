<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../generate-composer.php';

final class GenerateComposerTest extends TestCase
{
    public function test_dev_mode_resolves_sibling_requires_as_path_repos_with_dev_main(): void
    {
        $manifest = self::manifest();

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame([
            'php' => '^8.4',
            'kinetis/framework' => 'dev-main',
            'amphp/sql' => '^2',
        ], $out['require']);
        self::assertSame([['type' => 'path', 'url' => '../framework']], $out['repositories']);
        self::assertSame(['psr-4' => ['Kinetis\\Persistence\\' => 'src/']], $out['autoload']);
        self::assertSame(['psr-4' => ['Kinetis\\Persistence\\Tests\\' => 'tests/']], $out['autoload-dev']);
    }

    public function test_release_mode_resolves_siblings_to_the_exact_version_and_drops_the_repositories_key(): void
    {
        $manifest = self::manifest();

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: true);

        self::assertSame('^1.4.2', $out['require']['kinetis/framework']);
        self::assertArrayNotHasKey('repositories', $out);
    }

    public function test_a_release_constraint_pins_the_patch_it_was_built_against(): void
    {
        self::assertSame('^1.19.3', siblingConstraint('1.19.3'));
    }

    public function test_a_non_canonical_sibling_version_is_refused_rather_than_published(): void
    {
        $this->expectExceptionMessage('Not a canonical X.Y.Z version: 1.19');

        siblingConstraint('1.19');
    }

    public function test_require_dev_merges_the_defaults_and_sorts(): void
    {
        $manifest = self::manifest();

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame(['phpstan/phpstan', 'phpunit/phpunit'], array_keys($out['require-dev']));
    }

    public function test_a_require_dev_override_replaces_the_defaults_entirely(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['persistence']['requireDevOverride'] = ['phpunit/phpunit' => '^11'];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame(['phpunit/phpunit' => '^11'], $out['require-dev']);
    }

    public function test_a_dev_sibling_becomes_a_path_repo_ahead_of_the_runtime_siblings(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['persistence']['requiresDev'] = ['tooling'];
        $manifest['packages']['tooling'] = [
            'name' => 'kinetis/tooling',
            'description' => 'tooling',
            'namespace' => 'Kinetis\\Tooling\\',
            'version' => '1.0.0',
        ];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame('dev-main', $out['require-dev']['kinetis/tooling']);
        self::assertSame([
            ['type' => 'path', 'url' => '../tooling'],
            ['type' => 'path', 'url' => '../framework'],
        ], $out['repositories']);
    }

    public function test_infection_in_require_dev_allows_its_installer_plugin(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['persistence']['requireDevExtra'] = ['infection/infection' => '^0.29'];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame(['infection/extension-installer' => true], $out['config']['allow-plugins']);
    }

    public function test_an_empty_kinetis_block_still_marks_the_package_for_discovery(): void
    {
        $manifest = self::manifest();
        $manifest['packages']['persistence']['kinetis'] = [];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame('{"kinetis":{}}', json_encode($out['extra']));
    }

    public function test_generated_json_ends_with_one_newline(): void
    {
        self::assertStringEndsWith("}\n", encodeComposerJson(['a' => 1]));
    }

    public function test_the_committed_composer_json_files_match_the_manifest(): void
    {
        self::assertSame([], findStalePackages(loadManifest()));
    }

    public function test_a_patch_bump_moves_one_step(): void
    {
        self::assertSame(
            ['persistence' => '1.0.1'],
            self::moves(['--bump=persistence', '--patch']),
        );
    }

    public function test_a_minor_bump_resets_the_patch(): void
    {
        self::assertSame(
            ['persistence' => '1.1.0'],
            self::moves(['--bump=persistence', '--minor']),
        );
    }

    public function test_bump_all_moves_every_package(): void
    {
        self::assertSame(
            ['framework' => '1.4.3', 'persistence' => '1.0.1'],
            self::moves(['--bump=all', '--patch']),
        );
    }

    public function test_set_version_names_the_target_directly(): void
    {
        self::assertSame(
            ['persistence' => '1.1.0'],
            self::moves(['--set-version=persistence=1.1.0']),
        );
    }

    public function test_set_version_refuses_a_move_the_validator_would_reject(): void
    {
        $problems = planVersionMoves(self::manifest(), parseGeneratorArguments(['--set-version=persistence=1.0.3']));

        self::assertSame([], $problems['versions']);
        self::assertStringContainsString('persistence: version jumped', $problems['problems'][0]);
    }

    public function test_one_rejected_key_leaves_the_whole_move_unapplied(): void
    {
        $plan = planVersionMoves(
            self::manifest(),
            parseGeneratorArguments(['--set-version=persistence=1.0.1', '--set-version=framework=2.0.0']),
        );

        self::assertSame(['persistence' => '1.0.1'], $plan['versions']);
        self::assertStringContainsString('framework: version', $plan['problems'][0]);
    }

    public function test_an_unknown_package_is_named(): void
    {
        $plan = planVersionMoves(self::manifest(), parseGeneratorArguments(['--bump=nowhere', '--patch']));

        self::assertSame(['Unknown package: nowhere'], $plan['problems']);
    }

    public function test_bump_and_set_version_naming_the_same_package_is_rejected(): void
    {
        $plan = planVersionMoves(
            self::manifest(),
            parseGeneratorArguments(['--bump=persistence', '--patch', '--set-version=persistence=1.1.0']),
        );

        self::assertStringContainsString('pick one', $plan['problems'][0]);
    }

    public function test_no_arguments_writes_every_package(): void
    {
        self::assertSame('write', parseGeneratorArguments([])['mode']);
    }

    public function test_an_unknown_option_is_named(): void
    {
        self::assertSame(['Unknown option: --nope'], parseGeneratorArguments(['--nope'])['problems']);
    }

    public function test_a_bump_without_a_size_is_rejected(): void
    {
        self::assertSame(['--bump requires --minor or --patch.'], parseGeneratorArguments(['--bump=a'])['problems']);
    }

    public function test_a_size_without_a_bump_is_rejected(): void
    {
        self::assertSame(
            ["--patch sizes a --bump, which this invocation doesn't have."],
            parseGeneratorArguments(['--patch'])['problems'],
        );
    }

    public function test_two_sizes_are_rejected(): void
    {
        self::assertContains('Pick one of --minor or --patch, not both.', parseGeneratorArguments(['--bump=a', '--patch', '--minor'])['problems']);
    }

    public function test_a_repeated_bump_is_rejected(): void
    {
        self::assertContains('--bump is given more than once.', parseGeneratorArguments(['--bump=a', '--bump=b', '--patch'])['problems']);
    }

    public function test_two_modes_at_once_are_rejected(): void
    {
        self::assertSame(
            ['These modes cannot run together: check, release.'],
            parseGeneratorArguments(['--check', '--release=a'])['problems'],
        );
    }

    public function test_set_version_needs_a_key_and_a_version(): void
    {
        self::assertSame(
            ["--set-version needs <key>=<version>, got 'persistence'"],
            parseGeneratorArguments(['--set-version=persistence'])['problems'],
        );
    }

    public function test_release_write_writes_release_mode_json_under_the_given_root(): void
    {
        $root = self::scratchRoot(['framework', 'persistence']);

        self::assertSame(0, runReleaseWrite(self::manifest(), 'persistence', $root));

        $written = json_decode((string) file_get_contents("{$root}/packages/persistence/composer.json"), true);

        self::assertSame('^1.4.2', $written['require']['kinetis/framework']);
        self::assertArrayNotHasKey('repositories', $written);
    }

    public function test_release_write_refuses_an_unknown_package(): void
    {
        self::assertSame(1, runReleaseWrite(self::manifest(), 'nowhere', self::scratchRoot([])));
    }

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    private static function moves(array $argv): array
    {
        return planVersionMoves(self::manifest(), parseGeneratorArguments($argv))['versions'];
    }

    /** @param list<string> $keys */
    private static function scratchRoot(array $keys): string
    {
        $root = sys_get_temp_dir() . '/kinetis-generate-' . bin2hex(random_bytes(6));

        foreach ($keys as $key) {
            mkdir("{$root}/packages/{$key}", 0o755, recursive: true);
        }

        if ($keys === []) {
            mkdir($root, 0o755, recursive: true);
        }

        return $root;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return [
            'defaults' => [
                'type' => 'library',
                'license' => 'MIT',
                'authors' => [['name' => 'Test']],
                'minimumStability' => 'stable',
                'preferStable' => true,
                'phpVersion' => '^8.4',
                'requireDev' => ['phpunit/phpunit' => '^12.5', 'phpstan/phpstan' => '^2.2'],
            ],
            'packages' => [
                'framework' => [
                    'name' => 'kinetis/framework',
                    'description' => 'core',
                    'namespace' => 'Kinetis\\',
                    'version' => '1.4.2',
                ],
                'persistence' => [
                    'name' => 'kinetis/persistence',
                    'description' => 'persistence',
                    'namespace' => 'Kinetis\\Persistence\\',
                    'testNamespace' => 'Kinetis\\Persistence\\Tests\\',
                    'requires' => ['framework'],
                    'require' => ['amphp/sql' => '^2'],
                    'version' => '1.0.0',
                ],
            ],
        ];
    }
}
