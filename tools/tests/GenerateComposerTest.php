<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

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

        self::assertSame('^1.4', $out['require']['kinetis/kinetis']);
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
        self::assertSame('^2.0', $release['require']['kinetis/http-client']);
        self::assertSame('^1.0', $release['require-dev']['kinetis/kinetis']);
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

    public function test_major_minor_constraint_drops_the_patch_component(): void
    {
        self::assertSame('^1.4', majorMinorConstraint('1.4.2'));
        self::assertSame('^2.0', majorMinorConstraint('2.0.0'));
    }

    public function test_parse_semver_accepts_plain_x_y_z(): void
    {
        self::assertSame(['major' => 1, 'minor' => 4, 'patch' => 2], parseSemver('1.4.2'));
    }

    public function test_bump_version_major_resets_minor_and_patch(): void
    {
        self::assertSame('2.0.0', bumpVersion('1.4.2', 'major'));
    }

    public function test_bump_version_minor_resets_patch_only(): void
    {
        self::assertSame('1.5.0', bumpVersion('1.4.2', 'minor'));
    }

    public function test_bump_version_patch_increments_patch_only(): void
    {
        self::assertSame('1.4.3', bumpVersion('1.4.2', 'patch'));
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
