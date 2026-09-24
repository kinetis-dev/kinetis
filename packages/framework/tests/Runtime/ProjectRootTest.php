<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Cache\BootSequence;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Tests\Runtime\Fixtures\ProjectRootPackageBootstrap;
use PHPUnit\Framework\TestCase;

final class ProjectRootTest extends TestCase
{
    /**
     * vendor/bin/phpunit is itself a real Composer-generated bin-proxy —
     * it sets $GLOBALS['_composer_bin_dir'] unconditionally before PHPUnit
     * runs a single test, for the whole process. Passing composerBinDir:
     * null alone doesn't exercise the "absent" fallback branch unless that
     * ambient global is cleared first: detect() still prefers it via ??=.
     */
    public function test_falls_back_to_one_level_above_the_caller_directory_when_absent(): void
    {
        $original = $GLOBALS['_composer_bin_dir'] ?? null;
        unset($GLOBALS['_composer_bin_dir']);

        try {
            self::assertSame('/app', ProjectRoot::detect('/app/public', composerBinDir: null));
        } finally {
            if ($original !== null) {
                $GLOBALS['_composer_bin_dir'] = $original;
            }
        }
    }

    public function test_uses_composer_bin_dir_two_levels_up_when_present(): void
    {
        self::assertSame('/app', ProjectRoot::detect('/somewhere/irrelevant', composerBinDir: '/app/vendor/bin'));
    }

    public function test_an_instance_carries_the_given_path_verbatim(): void
    {
        self::assertSame('/app/../app/', new ProjectRoot('/app/../app/')->path);
    }

    public function test_boot_sequence_binds_the_root_before_package_bootstraps_run(): void
    {
        $root = $this->temporaryRoot();

        try {
            $this->runBootSequence($root, runBootstrap: true);

            self::assertSame($root, file_get_contents($root . '/' . ProjectRootPackageBootstrap::MARKER));
        } finally {
            @unlink($root . '/' . ProjectRootPackageBootstrap::MARKER);
            rmdir($root);
        }
    }

    /**
     * A #[Command(bootstrap: false)] command such as `kinetis build`
     * resolves its root from this binding.
     */
    public function test_boot_sequence_binds_the_root_when_the_bootstrap_chain_is_skipped(): void
    {
        $root = $this->temporaryRoot();

        try {
            $app = $this->runBootSequence($root, runBootstrap: false);

            self::assertSame($root, $app->get(ProjectRoot::class)->path);
            self::assertFileDoesNotExist($root . '/' . ProjectRootPackageBootstrap::MARKER);
        } finally {
            rmdir($root);
        }
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/kinetis_project_root_' . bin2hex(random_bytes(8));
        mkdir($root);

        return $root;
    }

    private function runBootSequence(string $root, bool $runBootstrap): AppScope
    {
        $app = new AppScope();

        // The root has no bootstrap.php, so only the listed package
        // bootstrap runs.
        BootSequence::run(
            $app,
            $root,
            new Config([]),
            new EventListenerRegistry(),
            [],
            [ProjectRootPackageBootstrap::class],
            $runBootstrap,
        );

        return $app;
    }
}
