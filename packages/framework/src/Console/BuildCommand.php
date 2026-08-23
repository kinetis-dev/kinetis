<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Console\Attributes\Command;
use Kinetis\Runtime\ProjectRoot;

/**
 * The one command that can never be found *only* through the cache it
 * itself produces — bin/kinetis's bootstrap still has to discover this
 * class (live, or from a stale-but-existing cache) before it can even run
 * it, the same "auto-generate on first run" path any other command's
 * first-ever invocation goes through too. Runs unconditionally regardless
 * of APP_ENV — an explicit, imperative action, not the environment-gated
 * automatic lazy-compile path bin/kinetis's own bootstrap takes to find a
 * command by name in the first place.
 *
 * Lives under Kinetis\Console like any other command — discovered the
 * same way, with no special-casing left anywhere in bin/kinetis.
 */
final readonly class BuildCommand
{
    /**
     * $projectRootOverride is accepted as an optional constructor
     * parameter for the same testability reason as AppEnvironment::
     * detect()/ProjectRoot::detect() itself — exercising this against a
     * temp fixture directory instead of this repo's own real root.
     * Autowire::instantiate() already tolerates an unresolvable scalar
     * parameter with a default value, so this costs nothing when the
     * container constructs this class normally.
     */
    public function __construct(
        private ?string $projectRootOverride = null,
    ) {}

    #[Command('build', description: 'Compiles routes, MCP tools/resources, commands, and event listeners ahead of time', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        // dirname(__DIR__): this file lives one level deeper than
        // bin/kinetis does (src/Console/ vs bin/), so ProjectRoot::detect()
        // — which expects "my own directory's parent is the project root"
        // when not run through a real Composer bin-proxy — needs src/, not
        // src/Console/, to keep resolving correctly in that fallback case.
        // The bin-proxy branch itself ignores this argument entirely, so
        // it's only the non-proxied (this monorepo's own dev/test) case
        // this actually matters for.
        $projectRoot = $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__));
        $cacheDirectory = $projectRoot . '/.kinetis-cache';

        self::removeDirectory($cacheDirectory);

        if ($arguments->hasOption('destroy')) {
            fwrite(STDOUT, "Removed {$cacheDirectory}/\n");

            return 0;
        }

        $compiled = (new Compiler())->compileProject($projectRoot);
        (new CacheStore($cacheDirectory))->writeAll($compiled);

        fwrite(STDOUT, "Compiled routes, MCP tools/resources, commands, and event listeners written to {$cacheDirectory}/\n");

        return 0;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
}
