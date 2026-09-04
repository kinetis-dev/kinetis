<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../manifest-schema.php';

final class ManifestSchemaTest extends TestCase
{
    /** @var list<string> */
    private array $scratchRoots = [];

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'type' => 'library',
            'license' => 'MIT',
            'authors' => [['name' => 'Test']],
            'minimumStability' => 'stable',
            'preferStable' => true,
            'phpVersion' => '^8.4',
            'requireDev' => ['phpunit/phpunit' => '^12.5'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function package(string $key, array $overrides = []): array
    {
        return [
            'name' => "kinetis/{$key}",
            'description' => 'a package',
            'namespace' => 'Kinetis\\Demo\\',
            'version' => '1.0.0',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    private static function manifest(array $packages): array
    {
        return ['defaults' => self::defaults(), 'packages' => $packages];
    }

    public function test_a_well_formed_manifest_reports_nothing(): void
    {
        $manifest = self::manifest([
            'framework' => self::package('framework'),
            'persistence' => self::package('persistence', ['requires' => ['framework']]),
        ]);

        self::assertSame([], manifestProblems($manifest));
    }

    public function test_the_repos_own_manifest_passes_the_schema_including_its_on_disk_checks(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../packages.manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame([], manifestProblems($manifest, __DIR__ . '/../..'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePackageKeys(): iterable
    {
        yield 'a path separator' => ['queue/redis'];
        yield 'a parent directory' => ['..'];
        yield 'a current directory' => ['.'];
        yield 'a leading dot' => ['.hidden'];
        yield 'an absolute path' => ['/etc'];
        yield 'a traversal' => ['../../etc'];
        yield 'a space' => ['queue redis'];
        yield 'an uppercase letter' => ['Framework'];
        yield 'a shell metacharacter' => ['queue;rm'];
        yield 'a tilde' => ['~'];
        yield 'empty' => [''];
        yield 'a trailing dash' => ['queue-'];
        yield 'a leading dash' => ['-queue'];
    }

    #[DataProvider('unsafePackageKeys')]
    public function test_an_unsafe_package_key_is_rejected(string $key): void
    {
        $problems = manifestProblems(self::manifest([$key => self::package($key)]));

        self::assertNotSame([], $problems);
        self::assertStringContainsString('not a safe package key', implode("\n", $problems));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedDependencyNames(): iterable
    {
        yield 'a platform requirement' => ['php'];
        yield 'an extension' => ['ext-fileinfo'];
        yield 'an extension with an underscore' => ['ext-pdo_mysql'];
        yield 'a vendor package' => ['nyholm/psr7'];
        yield 'a name with dashes' => ['league/flysystem-aws-s3-v3'];
        yield 'a name with a dot' => ['phpunit/php-code-coverage'];
    }

    /**
     * These names become keys in every generated composer.json, so the
     * rule has to admit each shape the manifest uses.
     */
    #[DataProvider('acceptedDependencyNames')]
    public function test_a_real_dependency_name_is_accepted(string $name): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', ['require' => [$name => '^1.0']])]);

        self::assertSame([], manifestProblems($manifest));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function badAuthorBlocks(): iterable
    {
        yield 'a bare string' => ['Alon Noy', "'authors' must be a non-empty list"];
        yield 'an empty list' => [[], "'authors' must be a non-empty list"];
        yield 'a map' => [['name' => 'Alon Noy'], "'authors' must be a non-empty list"];
        yield 'a list of strings' => [['Alon Noy'], 'author 0 must be an object'];
        yield 'a nested list' => [[[['name' => 'Alon Noy']]], 'author 0 must be an object'];
        yield 'no name' => [[['email' => 'alon@example.com']], "author 0 needs a 'name'"];
        yield 'a blank name' => [[['name' => '  ']], "author 0 needs a 'name'"];
        yield 'a name that is a number' => [[['name' => 7]], "author 0 needs a 'name'"];
        yield 'a nested email' => [[['name' => 'Alon Noy', 'email' => ['a@b.c']]], "field 'email' must be a string"];
        yield 'an unknown field' => [[['name' => 'Alon Noy', 'twitter' => '@x']], "unknown field 'twitter'"];
    }

    /**
     * The authors block is copied verbatim into 27 generated files, so a
     * shape Composer cannot read has to stop here.
     */
    #[DataProvider('badAuthorBlocks')]
    public function test_an_authors_block_the_generator_cannot_use_is_rejected(mixed $authors, string $expected): void
    {
        $defaults = self::defaults();
        $defaults['authors'] = $authors;

        $problems = manifestProblems(['defaults' => $defaults, 'packages' => ['queue' => self::package('queue')]]);

        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    public function test_a_full_author_entry_is_accepted(): void
    {
        $defaults = self::defaults();
        $defaults['authors'] = [[
            'name' => 'Alon Noy',
            'email' => 'alon@example.com',
            'homepage' => 'https://example.com',
            'role' => 'Developer',
        ]];

        self::assertSame([], manifestProblems(['defaults' => $defaults, 'packages' => ['queue' => self::package('queue')]]));
    }

    public function test_a_package_name_that_does_not_match_its_key_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', ['name' => 'kinetis/queues'])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString("'name' must be kinetis/queue", $problems[0]);
    }

    public function test_a_package_name_outside_the_kinetis_vendor_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', ['name' => 'other/queue'])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString("'name' must be kinetis/queue", $problems[0]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badFieldTypes(): iterable
    {
        yield 'description is not a string' => [['description' => 42], "'description' must be a non-empty"];
        yield 'description is blank' => [['description' => '  '], "'description' must be a non-empty"];
        yield 'description holds a control byte' => [
            ['description' => "a package\0with a null"],
            "'description' must be a non-empty",
        ];
        yield 'a dependency name with a space' => [['require' => ['nyholm psr7' => '^1.8']], "'require' must be a map"];
        yield 'a dependency name with a null byte' => [
            ['require' => ["nyholm/psr7\0" => '^1.8']],
            "'require' must be a map",
        ];
        yield 'an uppercase dependency name' => [['require' => ['Nyholm/PSR7' => '^1.8']], "'require' must be a map"];
        yield 'an all-digit dependency name' => [['require' => ['1234' => '^1.8']], "'require' must be a map"];
        yield 'a constraint holding a newline' => [
            ['require' => ['nyholm/psr7' => "^1.8\n"]],
            "'require' must be a map",
        ];
        yield 'a bin path holding a control byte' => [['bin' => ["bin/kinetis\0"]], "'bin' entry"];
        yield 'an autoload path holding a newline' => [['autoloadFiles' => ["src/f.php\n"]], "'autoloadFiles' entry"];
        yield 'namespace misses its backslash' => [['namespace' => 'Kinetis\\Demo'], "'namespace' must be a PSR-4 namespace prefix"];
        yield 'testNamespace misses its backslash' => [['testNamespace' => 'Kinetis\\Demo\\Tests'], "'testNamespace' must be a PSR-4 namespace prefix"];
        yield 'version is not a string' => [['version' => 100], "'version' must be a string"];
        yield 'version is not semver' => [['version' => '1.0'], 'not a canonical X.Y.Z version'];
        yield 'version is off the 1.x line' => [['version' => '2.0.0'], 'not on the 1.x line'];
        yield 'require is a list' => [['require' => ['nyholm/psr7']], "'require' must be a map"];
        yield 'require holds a non-string constraint' => [['require' => ['nyholm/psr7' => 18]], "'require' must be a map"];
        yield 'requires is a map' => [['requires' => ['framework' => '^1.0']], "'requires' must be a list"];
        yield 'suggest is a list' => [['suggest' => ['ext-event']], "'suggest' must be a map"];
        yield 'bin is a string' => [['bin' => 'bin/kinetis'], "'bin' must be a list"];
        yield 'kinetis is a string' => [['kinetis' => 'yes'], "'kinetis' must be an object"];
        yield 'type is blank' => [['type' => ''], "'type' must be a non-empty string"];
        yield 'an unknown field' => [['schemaVersion' => 2], "unknown field 'schemaVersion'"];
        yield 'a package-wide drift flag' => [['allowVersionDrift' => true], "unknown field 'allowVersionDrift'"];
        yield 'a package-wide drift reason' => [['driftReason' => 'anything'], "unknown field 'driftReason'"];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('badFieldTypes')]
    public function test_a_field_of_the_wrong_type_is_rejected(array $overrides, string $expected): void
    {
        $problems = manifestProblems(self::manifest(['queue' => self::package('queue', $overrides)]));

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    /** @return iterable<string, array{string}> */
    public static function missingRequiredFields(): iterable
    {
        yield 'name' => ['name'];
        yield 'description' => ['description'];
        yield 'namespace' => ['namespace'];
        yield 'version' => ['version'];
    }

    #[DataProvider('missingRequiredFields')]
    public function test_a_missing_required_field_is_rejected(string $field): void
    {
        $package = self::package('queue');
        unset($package[$field]);

        $problems = manifestProblems(self::manifest(['queue' => $package]));

        self::assertSame(["queue: '{$field}' is missing"], $problems);
    }

    public function test_a_sibling_reference_to_a_package_that_does_not_exist_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', ['requires' => ['nowhere']])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('names nowhere, which is not a package', $problems[0]);
    }

    public function test_a_sibling_reference_to_the_package_itself_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', ['requires' => ['queue']])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString("'requires' lists the package itself", $problems[0]);
    }

    public function test_a_duplicated_sibling_reference_is_rejected(): void
    {
        $manifest = self::manifest([
            'framework' => self::package('framework'),
            'queue' => self::package('queue', ['requires' => ['framework', 'framework']]),
        ]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('listed more than once', $problems[0]);
    }

    public function test_a_sibling_listed_in_both_requires_and_requires_dev_is_rejected(): void
    {
        // It would generate a require and a require-dev entry for the
        // same package, plus two identical path repositories.
        $manifest = self::manifest([
            'framework' => self::package('framework'),
            'queue' => self::package('queue', ['requires' => ['framework'], 'requiresDev' => ['framework']]),
        ]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('listed more than once', $problems[0]);
    }

    public function test_a_drift_exemption_without_a_reason_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', [
            'require' => ['nyholm/psr7' => '^1.8'],
            'versionDriftExemptions' => ['nyholm/psr7' => ''],
        ])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('needs a reason', $problems[0]);
    }

    public function test_a_drift_exemption_with_a_blank_reason_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', [
            'require' => ['nyholm/psr7' => '^1.8'],
            'versionDriftExemptions' => ['nyholm/psr7' => "  \n "],
        ])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('needs a reason', $problems[0]);
    }

    public function test_a_drift_exemption_for_a_dependency_the_package_does_not_require_is_rejected(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', [
            'require' => ['nyholm/psr7' => '^1.8'],
            'versionDriftExemptions' => ['league/flysystem' => 'stale entry'],
        ])]);

        $problems = manifestProblems($manifest);

        self::assertCount(1, $problems);
        self::assertStringContainsString('which this package does not require', $problems[0]);
    }

    public function test_a_drift_exemption_naming_a_real_dependency_with_a_reason_passes(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', [
            'require' => ['nyholm/psr7' => '^1.8'],
            'versionDriftExemptions' => ['nyholm/psr7' => 'pinned to the version the wire fixtures were captured against'],
        ])]);

        self::assertSame([], manifestProblems($manifest));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableRelativePaths(): iterable
    {
        yield 'a parent directory' => ['../elsewhere/bin', 'relative path segment'];
        yield 'a deep traversal' => ['bin/../../escape', 'relative path segment'];
        yield 'a current directory segment' => ['./bin/kinetis', 'relative path segment'];
        yield 'an absolute path' => ['/usr/bin/kinetis', 'is absolute'];
        yield 'a doubled separator' => ['bin//kinetis', 'empty path segment'];
        yield 'a trailing separator' => ['bin/', 'empty path segment'];
        yield 'a control byte' => ["bin/kinetis\0", 'control byte'];
        yield 'a newline' => ["bin/kinetis\n", 'control byte'];

        // A backslash separates directories on Windows and is an
        // ordinary character here, so these read as confined on the
        // machine that generates the composer.json and escape on the one
        // that installs it.
        yield 'a Windows traversal' => ['..\\outside.php', 'backslash'];
        yield 'a Windows separator' => ['bin\\kinetis', 'backslash'];
        yield 'a UNC host' => ['\\\\server\\share\\x.php', 'backslash'];
        yield 'a drive-relative path' => ['C:relative.php', 'drive letter'];
        yield 'a drive-absolute path' => ['C:\\absolute.php', 'drive letter'];
        yield 'a lowercase drive' => ['c:/absolute.php', 'drive letter'];
    }

    /**
     * These values ship in the published composer.json and are resolved
     * by whichever machine installs the package, so one spelling has to
     * mean the same thing everywhere it is read.
     */
    #[DataProvider('unusableRelativePaths')]
    public function test_a_declared_path_outside_the_portable_grammar_is_rejected(string $path, string $expected): void
    {
        $problems = manifestProblems(self::manifest(['queue' => self::package('queue', ['bin' => [$path]])]));

        self::assertNotSame([], $problems, "{$path} must be rejected");
        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    /** @return iterable<string, array{string, string}> */
    public static function platformInvalidRelativePaths(): iterable
    {
        yield 'an alternate data stream' => ['src/Thing.php:hidden', 'holds :'];
        yield 'a bare colon' => ['src/a:b', 'holds :'];
        yield 'a less-than' => ['src/a<b.php', 'holds <'];
        yield 'a greater-than' => ['src/a>b.php', 'holds >'];
        yield 'a quote' => ['src/a"b.php', 'holds "'];
        yield 'a pipe' => ['src/a|b.php', 'holds |'];
        yield 'a question mark' => ['src/a?b.php', 'holds ?'];
        yield 'an asterisk' => ['src/a*b.php', 'holds *'];
        yield 'a trailing dot' => ['src/Thing.', 'dot or a space'];
        yield 'a trailing space' => ['src/Thing ', 'dot or a space'];
        yield 'a directory with a trailing dot' => ['src./Thing.php', 'dot or a space'];
        yield 'a device name' => ['CON', 'device name'];
        yield 'a device name in lower case' => ['con', 'device name'];
        yield 'a device name in mixed case' => ['CoN', 'device name'];
        yield 'a device name with an extension' => ['con.php', 'device name'];
        yield 'a device name in a subdirectory' => ['src/NUL.txt', 'device name'];
        yield 'a device directory' => ['aux/Thing.php', 'device name'];
        yield 'a serial device' => ['COM1.php', 'device name'];
        yield 'a printer device' => ['lpt9', 'device name'];
    }

    /**
     * A path this machine writes without complaint and the machine
     * installing the package cannot open. The contract is one spelling
     * that resolves the same way on every supported platform, so these
     * are rejected where they are written rather than where they break.
     */
    #[DataProvider('platformInvalidRelativePaths')]
    public function test_a_declared_path_no_supported_platform_can_resolve_is_rejected(
        string $path,
        string $expected,
    ): void {
        $problem = packageRelativePathProblem($path);

        self::assertNotNull($problem, "{$path} must be rejected");
        self::assertStringContainsString($expected, $problem);
    }

    /**
     * The device-name rule matches a whole segment stem, not a prefix —
     * these are ordinary names that merely start with one.
     */
    public function test_a_name_that_merely_begins_with_a_device_name_is_accepted(): void
    {
        foreach (['src/console.php', 'src/nulls.php', 'src/auxiliary.php', 'src/com10.php', 'prnt'] as $path) {
            self::assertNull(packageRelativePathProblem($path), "{$path} is an ordinary name");
        }
    }

    /**
     * The two paths the manifest declares. They have to keep passing the
     * grammar that catches everything else.
     */
    public function test_the_paths_this_repository_declares_stay_valid(): void
    {
        self::assertNull(packageRelativePathProblem('bin/kinetis'));
        self::assertNull(packageRelativePathProblem('src/Async/functions.php'));
    }

    /** @return iterable<string, array{string}> */
    public static function portableRelativePaths(): iterable
    {
        yield 'a file at the package root' => ['kinetis'];
        yield 'a file in a directory' => ['bin/kinetis'];
        yield 'a nested file' => ['src/Async/functions.php'];
        yield 'a name holding a dot' => ['src/a.b.php'];
        yield 'a name holding a dash' => ['src/a-b.php'];
        yield 'a name starting with a dot' => ['src/.hidden.php'];
    }

    #[DataProvider('portableRelativePaths')]
    public function test_a_portable_declared_path_is_accepted(string $path): void
    {
        self::assertNull(packageRelativePathProblem($path));
    }

    /**
     * The grammar is judged on the spelling alone, before anything
     * touches the filesystem — the machine that reads these values is
     * not this one.
     */
    public function test_the_path_grammar_is_applied_without_a_project_root(): void
    {
        $problems = manifestProblems(self::manifest([
            'queue' => self::package('queue', ['autoloadFiles' => ['..\\outside.php']]),
        ]));

        self::assertCount(1, $problems);
        self::assertStringContainsString('backslash', $problems[0]);
    }

    public function test_an_empty_declared_path_is_rejected_as_a_malformed_list(): void
    {
        $problems = manifestProblems(self::manifest(['queue' => self::package('queue', ['bin' => ['']])]));

        self::assertSame(["queue: 'bin' must be a list of package-relative paths"], $problems);
    }

    public function test_an_empty_path_is_rejected_by_the_grammar_too(): void
    {
        self::assertSame('is empty', packageRelativePathProblem(''));
    }

    public function test_a_declared_path_inside_the_package_directory_passes(): void
    {
        $manifest = self::manifest(['queue' => self::package('queue', [
            'bin' => ['bin/kinetis'],
            'autoloadFiles' => ['src/Async/functions.php'],
        ])]);

        self::assertSame([], manifestProblems($manifest));
    }

    public function test_the_generated_composer_json_path_stays_inside_its_package_directory(): void
    {
        $root = '/srv/monorepo';

        self::assertSame("{$root}/packages/queue/composer.json", composerJsonPath('queue', $root));
        self::assertTrue(pathIsWithin(composerJsonPath('queue', $root), packageDirectory('queue', $root)));
        self::assertFalse(pathIsWithin("{$root}/packages/queue-redis/composer.json", packageDirectory('queue', $root)));
    }

    public function test_path_containment_resolves_dot_segments_without_touching_the_filesystem(): void
    {
        self::assertSame('/srv/packages/queue', normalizePath('/srv/tools/../packages/./queue'));
        self::assertTrue(pathIsWithin('/srv/packages/queue/bin/kinetis', '/srv/packages/queue'));
        self::assertFalse(pathIsWithin('/srv/packages/queue/../queue-redis/x', '/srv/packages/queue'));
        self::assertFalse(pathIsWithin('/srv/packages/queue', '/srv/packages/queue'));
    }

    public function test_a_package_directory_with_no_manifest_entry_is_reported(): void
    {
        $root = $this->scratchRoot(['queue', 'orphan']);

        $problems = manifestProblems(self::manifest(['queue' => self::package('queue')]), $root);

        self::assertCount(1, $problems);
        self::assertStringContainsString('packages/orphan has no manifest entry', $problems[0]);
    }

    public function test_a_manifest_entry_with_no_package_directory_is_reported(): void
    {
        $root = $this->scratchRoot(['queue']);

        $problems = manifestProblems(
            self::manifest(['queue' => self::package('queue'), 'gone' => self::package('gone')]),
            $root,
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('packages/gone does not exist', $problems[0]);
    }

    public function test_a_manifest_with_no_packages_at_all_is_rejected(): void
    {
        self::assertSame(['manifest: packages is empty'], manifestProblems(self::manifest([])));
    }

    public function test_a_manifest_missing_its_defaults_block_is_rejected(): void
    {
        $problems = manifestProblems(['packages' => ['queue' => self::package('queue')]]);

        self::assertSame(["manifest: 'defaults' is missing or is not an object"], $problems);
    }

    public function test_an_unknown_top_level_field_is_rejected(): void
    {
        $manifest = [...self::manifest(['queue' => self::package('queue')]), 'schemaVersion' => 2];

        $problems = manifestProblems($manifest);

        self::assertContains("manifest: unknown field 'schemaVersion'", $problems);
    }

    public function test_a_defaults_block_missing_a_required_field_is_rejected(): void
    {
        $defaults = self::defaults();
        unset($defaults['phpVersion']);

        $problems = manifestProblems(['defaults' => $defaults, 'packages' => ['queue' => self::package('queue')]]);

        self::assertContains("defaults: 'phpVersion' must be a non-empty string", $problems);
    }

    public function test_something_that_is_not_an_object_at_all_is_rejected(): void
    {
        self::assertSame(['manifest: expected a JSON object, got string'], manifestProblems('a string'));
    }

    public function test_a_list_root_is_rejected_rather_than_indexed_into(): void
    {
        self::assertSame(['manifest: expected a JSON object, got array'], manifestProblems([1, 2]));
    }

    public function test_a_null_document_is_rejected(): void
    {
        self::assertSame(['manifest: expected a JSON object, got null'], manifestProblems(null));
    }

    public function test_a_declared_path_that_exists_inside_the_package_directory_passes(): void
    {
        $root = $this->scratchRoot(['queue']);
        mkdir("{$root}/packages/queue/bin", 0o777, true);
        file_put_contents("{$root}/packages/queue/bin/kinetis", "#!/usr/bin/env php\n");

        $manifest = self::manifest(['queue' => self::package('queue', ['bin' => ['bin/kinetis']])]);

        self::assertSame([], manifestProblems($manifest, $root));
    }

    /**
     * The path spells out as inside the package and resolves outside it.
     * Only the filesystem can tell the difference, so only a check that
     * asks the filesystem catches it.
     */
    public function test_a_declared_path_that_is_a_symlink_out_of_the_package_is_rejected(): void
    {
        $root = $this->scratchRoot(['queue']);
        mkdir("{$root}/outside", 0o777, true);
        file_put_contents("{$root}/outside/kinetis", "#!/usr/bin/env php\n");
        symlink("{$root}/outside/kinetis", "{$root}/packages/queue/kinetis");

        $manifest = self::manifest(['queue' => self::package('queue', ['bin' => ['kinetis']])]);

        $problems = manifestProblems($manifest, $root);

        self::assertCount(1, $problems);
        self::assertStringContainsString('reaches through a symlink', $problems[0]);
    }

    public function test_a_declared_path_reached_through_a_symlinked_directory_is_rejected(): void
    {
        $root = $this->scratchRoot(['queue']);
        mkdir("{$root}/outside/bin", 0o777, true);
        file_put_contents("{$root}/outside/bin/kinetis", "#!/usr/bin/env php\n");
        symlink("{$root}/outside/bin", "{$root}/packages/queue/bin");

        $manifest = self::manifest(['queue' => self::package('queue', ['bin' => ['bin/kinetis']])]);

        $problems = manifestProblems($manifest, $root);

        self::assertCount(1, $problems);
        self::assertStringContainsString('reaches through a symlink', $problems[0]);
    }

    /**
     * A link that stays inside the package is refused too. This tooling
     * writes into package directories and the release split reads them,
     * and neither follows a link to somewhere else usefully.
     */
    public function test_a_declared_path_that_is_a_symlink_within_the_package_is_rejected(): void
    {
        $root = $this->scratchRoot(['queue']);
        file_put_contents("{$root}/packages/queue/real", "#!/usr/bin/env php\n");
        symlink("{$root}/packages/queue/real", "{$root}/packages/queue/kinetis");

        $manifest = self::manifest(['queue' => self::package('queue', ['bin' => ['kinetis']])]);

        $problems = manifestProblems($manifest, $root);

        self::assertCount(1, $problems);
        self::assertStringContainsString('reaches through a symlink', $problems[0]);
    }

    public function test_a_declared_path_that_is_a_broken_symlink_is_rejected(): void
    {
        $root = $this->scratchRoot(['queue']);
        symlink("{$root}/never-existed", "{$root}/packages/queue/kinetis");

        $manifest = self::manifest(['queue' => self::package('queue', ['bin' => ['kinetis']])]);

        $problems = manifestProblems($manifest, $root);

        self::assertCount(1, $problems);
        self::assertStringContainsString('reaches through a symlink', $problems[0]);
    }

    /**
     * A path the manifest names before the file exists stays acceptable
     * on its spelling alone — that is all there is to judge it by.
     */
    public function test_a_declared_path_that_does_not_exist_yet_passes_on_its_spelling(): void
    {
        $root = $this->scratchRoot(['queue']);

        $manifest = self::manifest(['queue' => self::package('queue', ['autoloadFiles' => ['src/Async/functions.php']])]);

        self::assertSame([], manifestProblems($manifest, $root));
    }

    public function test_a_symlinked_package_directory_is_rejected(): void
    {
        $root = $this->scratchRoot([]);
        mkdir("{$root}/elsewhere/queue", 0o777, true);
        symlink("{$root}/elsewhere/queue", "{$root}/packages/queue");

        $problems = manifestProblems(self::manifest(['queue' => self::package('queue')]), $root);

        self::assertStringContainsString(
            'packages/queue is a symlink',
            implode("\n", $problems),
        );
    }

    public function test_a_symlinked_packages_root_is_rejected(): void
    {
        $root = sys_get_temp_dir() . '/kinetis-schema-' . bin2hex(random_bytes(6));
        $this->scratchRoots[] = $root;
        mkdir("{$root}/elsewhere/queue", 0o777, true);
        symlink("{$root}/elsewhere", "{$root}/packages");

        $problems = manifestProblems(self::manifest(['queue' => self::package('queue')]), $root);

        self::assertSame(
            ['manifest: packages/ is a symlink, which is not a directory this tooling writes into'],
            $problems,
        );
    }

    public function test_reading_a_manifest_that_is_not_there_reports_it_without_exiting(): void
    {
        $loaded = loadValidatedManifest(null, sys_get_temp_dir() . '/kinetis-absent-' . bin2hex(random_bytes(6)));

        self::assertNull($loaded['manifest']);
        self::assertCount(1, $loaded['problems']);
        self::assertStringContainsString('could not read', $loaded['problems'][0]);
    }

    public function test_a_path_that_is_not_a_readable_manifest_reports_a_problem_without_exiting(): void
    {
        // A directory stats fine and yields nothing to decode.
        $loaded = loadValidatedManifest(null, $this->scratchRoot([]));

        self::assertNull($loaded['manifest']);
        self::assertStringStartsWith('manifest: ', $loaded['problems'][0]);
    }

    public function test_an_empty_manifest_file_is_reported_without_exiting(): void
    {
        $loaded = loadValidatedManifest(null, $this->manifestFile(''));

        self::assertNull($loaded['manifest']);
        self::assertStringContainsString('the manifest is empty', $loaded['problems'][0]);
    }

    public function test_malformed_json_is_reported_without_exiting(): void
    {
        $path = $this->manifestFile("{ not json\n");

        $loaded = loadValidatedManifest(null, $path);

        self::assertNull($loaded['manifest']);
        self::assertStringContainsString('not valid JSON', $loaded['problems'][0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonObjectDocuments(): iterable
    {
        yield 'a scalar' => ['"a string"', 'expected a JSON object, got string'];
        yield 'a number' => ['42', 'expected a JSON object, got int'];
        yield 'a boolean' => ['true', 'expected a JSON object, got bool'];
        yield 'a list' => ['[1, 2]', 'expected a JSON object, got array'];
        yield 'null' => ['null', 'expected a JSON object, got null'];
    }

    /**
     * Valid JSON that is not an object reaches the schema as a problem
     * rather than a type error on the way in.
     */
    #[DataProvider('nonObjectDocuments')]
    public function test_a_document_that_is_not_an_object_is_reported(string $json, string $expected): void
    {
        $loaded = loadValidatedManifest(null, $this->manifestFile($json));

        self::assertNull($loaded['manifest']);
        self::assertStringContainsString($expected, $loaded['problems'][0]);
    }

    public function test_a_valid_manifest_comes_back_ready_to_use(): void
    {
        $path = $this->manifestFile(ManifestFixture::json(['queue' => '1.0.0']));

        $loaded = loadValidatedManifest(null, $path);

        self::assertSame([], $loaded['problems']);
        self::assertNotNull($loaded['manifest']);
        self::assertSame('1.0.0', $loaded['manifest']['packages']['queue']['version']);
    }

    private function manifestFile(string $contents): string
    {
        $directory = sys_get_temp_dir() . '/kinetis-schema-' . bin2hex(random_bytes(6));
        $this->scratchRoots[] = $directory;
        mkdir($directory, 0o777, true);
        $path = "{$directory}/packages.manifest.json";
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param list<string> $packageDirectories */
    private function scratchRoot(array $packageDirectories): string
    {
        $root = sys_get_temp_dir() . '/kinetis-schema-' . bin2hex(random_bytes(6));
        mkdir("{$root}/packages", 0o777, true);

        foreach ($packageDirectories as $directory) {
            mkdir("{$root}/packages/{$directory}", 0o777, true);
        }

        $this->scratchRoots[] = $root;

        return $root;
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchRoots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }

        $this->scratchRoots = [];
    }
}
