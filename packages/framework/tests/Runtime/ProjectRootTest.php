<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Runtime\ProjectRoot;
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
}
