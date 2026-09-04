<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../generate-composer.php';

final class GenerateComposerTest extends TestCase
{
    public function test_dev_mode_resolves_sibling_requires_as_path_repos_with_dev_main(): void
    {
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'kinetis' => ['name' => 'kinetis/kinetis', 'description' => 'core', 'namespace' => 'Kinetis\\'],
                'persistence' => [
                    'name' => 'kinetis/persistence',
                    'description' => 'persistence',
                    'namespace' => 'Kinetis\\Persistence\\',
                    'testNamespace' => 'Kinetis\\Persistence\\Tests\\',
                    'requires' => ['kinetis'],
                    'require' => ['amphp/sql' => '^2'],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: false);

        self::assertSame([
            'php' => '^8.4',
            'kinetis/kinetis' => 'dev-main',
            'amphp/sql' => '^2',
        ], $out['require']);
        self::assertSame([['type' => 'path', 'url' => '../kinetis']], $out['repositories']);
        self::assertSame(['psr-4' => ['Kinetis\\Persistence\\' => 'src/']], $out['autoload']);
        self::assertSame(['psr-4' => ['Kinetis\\Persistence\\Tests\\' => 'tests/']], $out['autoload-dev']);
    }

    public function test_release_mode_resolves_sibling_requires_as_real_constraints_with_no_repositories(): void
    {
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'kinetis' => ['name' => 'kinetis/kinetis', 'description' => 'core', 'namespace' => 'Kinetis\\', 'version' => '1.4.2'],
                'persistence' => [
                    'name' => 'kinetis/persistence',
                    'description' => 'persistence',
                    'namespace' => 'Kinetis\\Persistence\\',
                    'requires' => ['kinetis'],
                    'require' => ['amphp/sql' => '^2'],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $out = assembleComposerJson($manifest['packages']['persistence'], $manifest, release: true);

        self::assertSame('^1.4.2', $out['require']['kinetis/kinetis']);
        self::assertArrayNotHasKey('repositories', $out);
    }

    public function test_a_package_with_both_a_requires_and_a_requiresdev_sibling_resolves_both(): void
    {
        // Mirrors aws-sigv4: a real require sibling AND a require-dev-only
        // one, the one case in the real manifest where repositories'
        // ordering rule (requiresDev siblings first) actually matters.
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'kinetis' => ['name' => 'kinetis/kinetis', 'description' => 'core', 'namespace' => 'Kinetis\\', 'version' => '1.0.0'],
                'http-client' => ['name' => 'kinetis/http-client', 'description' => 'http', 'namespace' => 'Kinetis\\HttpClient\\', 'version' => '2.0.0'],
                'sigv4' => [
                    'name' => 'kinetis/sigv4',
                    'description' => 'sigv4',
                    'namespace' => 'Kinetis\\SigV4\\',
                    'requires' => ['http-client'],
                    'requiresDev' => ['kinetis'],
                    'require' => [],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $dev = assembleComposerJson($manifest['packages']['sigv4'], $manifest, release: false);
        self::assertSame('dev-main', $dev['require']['kinetis/http-client']);
        self::assertSame('dev-main', $dev['require-dev']['kinetis/kinetis']);
        self::assertSame(
            [['type' => 'path', 'url' => '../kinetis'], ['type' => 'path', 'url' => '../http-client']],
            $dev['repositories'],
        );

        $release = assembleComposerJson($manifest['packages']['sigv4'], $manifest, release: true);
        self::assertSame('^2.0.0', $release['require']['kinetis/http-client']);
        self::assertSame('^1.0.0', $release['require-dev']['kinetis/kinetis']);
        self::assertArrayNotHasKey('repositories', $release);
    }

    public function test_require_dev_override_replaces_defaults_entirely(): void
    {
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'demo' => [
                    'name' => 'kinetis/demo',
                    'description' => 'demo',
                    'namespace' => 'App\\',
                    'requireDevOverride' => ['phpstan/phpstan' => '^2.2'],
                    'require' => [],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $out = assembleComposerJson($manifest['packages']['demo'], $manifest, release: false);

        self::assertSame(['phpstan/phpstan' => '^2.2'], $out['require-dev']);
        self::assertArrayNotHasKey('allow-plugins', $out['config']);
    }

    public function test_require_dev_extra_merges_on_top_of_defaults_and_stays_sorted(): void
    {
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'demo' => [
                    'name' => 'kinetis/demo',
                    'description' => 'demo',
                    'namespace' => 'App\\',
                    'requireDevExtra' => ['nyholm/psr7' => '^1.8'],
                    'require' => [],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $out = assembleComposerJson($manifest['packages']['demo'], $manifest, release: false);

        self::assertSame(
            ['infection/infection', 'nyholm/psr7', 'phpstan/phpstan', 'phpunit/phpunit', 'vimeo/psalm'],
            array_keys($out['require-dev']),
        );
        self::assertSame(['infection/extension-installer' => true], $out['config']['allow-plugins']);
    }

    public function test_a_package_with_no_test_namespace_omits_autoload_dev(): void
    {
        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'demo' => [
                    'name' => 'kinetis/demo',
                    'description' => 'demo',
                    'namespace' => 'App\\',
                    'testNamespace' => null,
                    'require' => [],
                    'version' => '1.0.0',
                ],
            ],
        ];

        $out = assembleComposerJson($manifest['packages']['demo'], $manifest, release: false);

        self::assertArrayNotHasKey('autoload-dev', $out);
    }

    public function test_encode_composer_json_does_not_escape_slashes_or_unicode_and_ends_with_a_newline(): void
    {
        $encoded = encodeComposerJson(['description' => 'a — b/c']);

        self::assertStringContainsString('a — b/c', $encoded);
        self::assertStringNotContainsString('\\/', $encoded);
        self::assertStringNotContainsString('\\u', $encoded);
        self::assertStringEndsWith("}\n", $encoded);
    }

    public function test_a_sibling_constraint_carries_the_full_version(): void
    {
        // The patch is part of the floor: a package is only ever built
        // against one exact sibling version, and a patch release can add
        // public API.
        self::assertSame('^1.4.2', siblingConstraint('1.4.2'));
        self::assertSame('^2.0.0', siblingConstraint('2.0.0'));
    }

    public function test_a_sibling_constraint_rejects_a_version_it_cannot_parse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        siblingConstraint('1.4');
    }

    public function test_bump_and_set_version_reach_the_same_target_for_a_patch(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        self::assertSame(
            ['framework' => '1.4.3'],
            $this->targetsFor($manifest, ['--bump=framework', '--patch']),
        );
        self::assertSame(
            ['framework' => '1.4.3'],
            $this->targetsFor($manifest, ['--set-version=framework=1.4.3']),
        );
    }

    public function test_bump_and_set_version_reach_the_same_target_for_a_minor(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        self::assertSame(
            ['framework' => '1.5.0'],
            $this->targetsFor($manifest, ['--bump=framework', '--minor']),
        );
        self::assertSame(
            ['framework' => '1.5.0'],
            $this->targetsFor($manifest, ['--set-version=framework=1.5.0']),
        );
    }

    /**
     * --set-version is an explicit spelling of the same move, not a way
     * around the policy: anything --bump cannot produce, it cannot write.
     */
    public function test_set_version_is_held_to_the_same_policy_as_bump(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        foreach (['2.0.0', '1.4.5', '1.6.0', '1.5.1', '1.4.1', '1.4.2', '1.4.03'] as $rejected) {
            $plan = planVersionMoves($manifest, ['framework' => $rejected]);

            self::assertSame([], $plan['versions'], "{$rejected} must not be written");
            self::assertCount(1, $plan['problems'], "{$rejected} must be rejected");
        }
    }

    public function test_every_version_bump_produces_reaches_the_plan_intact(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        foreach (['patch' => '1.4.3', 'minor' => '1.5.0'] as $component => $expected) {
            $plan = planVersionMoves($manifest, $this->targetsFor($manifest, ['--bump=framework', "--{$component}"]));

            self::assertSame([], $plan['problems']);
            self::assertSame(['framework' => $expected], $plan['versions']);
        }
    }

    public function test_bump_all_moves_every_package_from_its_own_current_version(): void
    {
        $manifest = $this->twoPackageManifest('1.4.2', '1.1.0');

        self::assertSame(
            ['framework' => '1.5.0', 'queue' => '1.2.0'],
            $this->targetsFor($manifest, ['--bump=all', '--minor']),
        );
    }

    public function test_a_bump_that_would_overflow_a_component_is_rejected(): void
    {
        $manifest = $this->versionedManifest('1.0.' . PHP_INT_MAX);

        $problems = versionTargets($manifest, parseGeneratorArguments(['--bump=framework', '--patch']))['problems'];

        self::assertCount(1, $problems);
        self::assertStringContainsString('exceeds the largest version component', $problems[0]);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidInvocations(): iterable
    {
        yield 'an unknown option' => [['--force'], 'Unknown option: --force'];
        yield 'a misspelled mode' => [['--releaes=framework'], 'Unknown option: --releaes=framework'];
        yield 'a major bump' => [['--bump=framework', '--major'], 'Unknown option: --major'];
        yield 'a repeated bump' => [['--bump=framework', '--bump=queue', '--patch'], '--bump is given more than once.'];
        yield 'two different sizes' => [['--bump=framework', '--patch', '--minor'], 'Pick one of --minor or --patch'];
        yield 'the same size twice' => [['--bump=framework', '--patch', '--patch'], 'A bump size is given more than once.'];
        yield 'a size with no bump' => [['--patch'], "--patch sizes a --bump, which this invocation doesn't have."];
        yield 'a bump with no size' => [['--bump=framework'], '--bump requires --minor or --patch.'];
        yield 'a repeated set-version for one key' => [
            ['--set-version=framework=1.4.3', '--set-version=framework=1.5.0'],
            '--set-version names framework more than once.',
        ];
        yield 'check with a bump' => [['--check', '--bump=framework', '--patch'], 'cannot run together'];
        yield 'check with a release' => [['--check', '--release=framework'], 'cannot run together'];
        yield 'both release modes' => [['--release=framework', '--release-write=framework'], 'cannot run together'];
        yield 'a release with a bump' => [['--release=framework', '--bump=queue', '--patch'], 'cannot run together'];
        yield 'a release given twice' => [['--release=framework', '--release=queue'], '--release is given more than once.'];
        yield 'a set-version with no version' => [['--set-version=framework'], '--set-version needs <key>=<version>'];
    }

    /**
     * Every mode writes a file, so an invocation that could mean two
     * things is refused rather than resolved by whichever branch happens
     * to be tested first.
     *
     * @param list<string> $args
     */
    #[DataProvider('invalidInvocations')]
    public function test_an_invalid_invocation_is_refused(array $args, string $expected): void
    {
        $problems = parseGeneratorArguments($args)['problems'];

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' | ', $problems));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function validInvocations(): iterable
    {
        yield 'no arguments writes every package' => [[], 'write'];
        yield 'check' => [['--check'], 'check'];
        yield 'a patch bump' => [['--bump=framework', '--patch'], 'version'];
        yield 'a minor bump' => [['--bump=framework', '--minor'], 'version'];
        yield 'a set-version' => [['--set-version=framework=1.4.3'], 'version'];
        yield 'two set-versions for different keys' => [
            ['--set-version=framework=1.4.3', '--set-version=queue=1.1.1'],
            'version',
        ];
        yield 'a release preview' => [['--release=framework'], 'release'];
        yield 'a release write' => [['--release-write=framework'], 'release-write'];
    }

    /** @param list<string> $args */
    #[DataProvider('validInvocations')]
    public function test_a_valid_invocation_resolves_to_one_mode(array $args, string $mode): void
    {
        $parsed = parseGeneratorArguments($args);

        self::assertSame([], $parsed['problems']);
        self::assertSame($mode, $parsed['mode']);
    }

    public function test_an_unknown_package_is_rejected_before_anything_is_planned(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        $requested = versionTargets($manifest, parseGeneratorArguments(['--bump=nowhere', '--patch']));

        self::assertSame([], $requested['targets']);
        self::assertSame(['Unknown package: nowhere'], $requested['problems']);
    }

    public function test_naming_a_package_through_both_flags_at_once_is_rejected(): void
    {
        $manifest = $this->versionedManifest('1.4.2');

        $requested = versionTargets(
            $manifest,
            parseGeneratorArguments(['--bump=framework', '--patch', '--set-version=framework=1.5.0']),
        );

        self::assertSame(['framework: --bump and --set-version both name it; pick one'], $requested['problems']);
    }

    /**
     * One rejected key must not leave the others already applied — the
     * manifest is written once, after every move has been accepted.
     */
    public function test_one_rejected_move_takes_the_whole_batch_with_it(): void
    {
        $manifest = $this->twoPackageManifest('1.4.2', '1.1.0');

        $plan = planVersionMoves($manifest, ['framework' => '1.4.3', 'queue' => '2.0.0']);

        self::assertSame(['framework' => '1.4.3'], $plan['versions']);
        self::assertCount(1, $plan['problems']);
        self::assertStringContainsString('queue:', $plan['problems'][0]);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $args
     * @return array<string, string>
     */
    private function targetsFor(array $manifest, array $args): array
    {
        $parsed = parseGeneratorArguments($args);

        self::assertSame([], $parsed['problems']);

        return versionTargets($manifest, $parsed)['targets'];
    }

    /** @return array<string, mixed> */
    private function twoPackageManifest(string $framework, string $queue): array
    {
        return [
            'defaults' => $this->defaults(),
            'packages' => [
                'framework' => ['name' => 'kinetis/framework', 'description' => 'x', 'namespace' => 'App\\', 'version' => $framework],
                'queue' => ['name' => 'kinetis/queue', 'description' => 'x', 'namespace' => 'App\\', 'version' => $queue],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function versionedManifest(string $version): array
    {
        return [
            'defaults' => $this->defaults(),
            'packages' => [
                'framework' => ['name' => 'kinetis/framework', 'description' => 'x', 'namespace' => 'App\\', 'version' => $version],
            ],
        ];
    }

    public function test_find_stale_packages_detects_a_missing_and_a_mismatched_file(): void
    {
        $dir = sys_get_temp_dir() . '/kinetis-tools-test-' . uniqid();
        mkdir("{$dir}/packages/fresh", recursive: true);
        mkdir("{$dir}/packages/stale", recursive: true);
        mkdir("{$dir}/packages/missing", recursive: true);

        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'fresh' => ['name' => 'kinetis/fresh', 'description' => 'x', 'namespace' => 'App\\', 'require' => [], 'version' => '1.0.0'],
                'stale' => ['name' => 'kinetis/stale', 'description' => 'x', 'namespace' => 'App\\', 'require' => [], 'version' => '1.0.0'],
                'missing' => ['name' => 'kinetis/missing', 'description' => 'x', 'namespace' => 'App\\', 'require' => [], 'version' => '1.0.0'],
            ],
        ];

        file_put_contents(
            "{$dir}/packages/fresh/composer.json",
            encodeComposerJson(assembleComposerJson($manifest['packages']['fresh'], $manifest, release: false)),
        );
        file_put_contents("{$dir}/packages/stale/composer.json", "{}\n");
        // packages/missing/composer.json deliberately never written.

        $stale = findStalePackages($manifest, $dir);

        self::assertSame(['stale', 'missing'], $stale);

        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_release_write_writes_release_mode_content_to_disk(): void
    {
        $dir = sys_get_temp_dir() . '/kinetis-tools-test-' . uniqid();
        mkdir("{$dir}/packages/leaf", recursive: true);
        mkdir("{$dir}/packages/root", recursive: true);

        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'root' => ['name' => 'kinetis/root', 'description' => 'x', 'namespace' => 'App\\', 'require' => [], 'version' => '2.1.0'],
                'leaf' => ['name' => 'kinetis/leaf', 'description' => 'x', 'namespace' => 'App\\', 'requires' => ['root'], 'require' => [], 'version' => '1.0.0'],
            ],
        ];

        $exitCode = runReleaseWrite($manifest, 'leaf', $dir);
        $written = json_decode(
            (string) file_get_contents("{$dir}/packages/leaf/composer.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(0, $exitCode);
        self::assertArrayNotHasKey('repositories', $written);
        self::assertSame('^2.1.0', $written['require']['kinetis/root']);
        // root itself was never named, so it must not have been touched
        // — release-write only ever writes the keys it was given.
        self::assertFileDoesNotExist("{$dir}/packages/root/composer.json");

        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_release_write_rejects_an_unknown_package_and_writes_nothing(): void
    {
        $dir = sys_get_temp_dir() . '/kinetis-tools-test-' . uniqid();
        mkdir("{$dir}/packages/leaf", recursive: true);

        $manifest = [
            'defaults' => $this->defaults(),
            'packages' => [
                'leaf' => ['name' => 'kinetis/leaf', 'description' => 'x', 'namespace' => 'App\\', 'require' => [], 'version' => '1.0.0'],
            ],
        ];

        $exitCode = runReleaseWrite($manifest, 'leaf,does-not-exist', $dir);

        self::assertSame(1, $exitCode);
        self::assertFileDoesNotExist("{$dir}/packages/leaf/composer.json");

        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'type' => 'library',
            'license' => 'MIT',
            'authors' => [['name' => 'Test']],
            'minimumStability' => 'stable',
            'preferStable' => true,
            'phpVersion' => '^8.4',
            'requireDev' => [
                'infection/infection' => '^0.34.2',
                'phpstan/phpstan' => '^2.2',
                'phpunit/phpunit' => '^11.0',
                'vimeo/psalm' => '^6.16',
            ],
        ];
    }
}
