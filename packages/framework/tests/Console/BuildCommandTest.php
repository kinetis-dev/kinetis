<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Exception\CacheWriteException;
use Kinetis\Console\BuildCommand;
use Kinetis\Console\CommandArguments;
use PHPUnit\Framework\TestCase;

final class BuildCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/kinetis_build_command_test_' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    private function cacheStore(): CacheStore
    {
        return new CacheStore($this->projectRoot . '/.kinetis-cache');
    }

    public function test_writes_a_loadable_cache_and_returns_success(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);

        $exitCode = $command->run(CommandArguments::parse([]));

        self::assertSame(0, $exitCode);
        self::assertFileExists($this->projectRoot . '/.kinetis-cache/current');

        $store = $this->cacheStore();
        self::assertTrue($store->exists());
        self::assertNotNull($store->loadHttp());
        self::assertNotNull($store->loadCommands());
        self::assertNotNull($store->loadEvents());
        self::assertNotNull($store->loadPlugins());
    }

    /**
     * A plain rebuild compiles and stages a whole new generation before
     * publishing it, so the generation a first successful build already
     * produced is never removed just because a second build ran — see
     * CacheStore::writeAll()'s own docblock on retention.
     */
    public function test_a_second_successful_build_publishes_a_new_generation_without_deleting_the_first(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));

        $firstGenerationDirectory = $this->cacheStore()->activeGenerationDirectory();
        self::assertNotNull($firstGenerationDirectory);

        $exitCode = $command->run(CommandArguments::parse([]));

        self::assertSame(0, $exitCode);
        self::assertDirectoryExists($firstGenerationDirectory, 'a plain rebuild must not delete a previously-published generation');

        $secondGenerationDirectory = $this->cacheStore()->activeGenerationDirectory();
        self::assertNotNull($secondGenerationDirectory);
        self::assertNotSame($firstGenerationDirectory, $secondGenerationDirectory, 'the second build must have published a genuinely new generation, not reused the first');
    }

    /**
     * A rebuild that fails partway through compiling or writing must
     * leave whatever was already active exactly as it was, not an
     * already-deleted cache directory. A real DTO whose constructor
     * default is a live object
     * (PHP 8.1's "new in initializers") is the genuine, real-world way a
     * compile pass produces something CacheStore::writeAll() cannot
     * var_export() back — the same mechanism CacheStoreTest's own
     * identical case exercises directly against CacheStore, exercised
     * here through the actual BuildCommand a deploy pipeline runs.
     */
    public function test_a_failed_rebuild_leaves_the_previously_active_generation_loadable_and_unchanged(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));

        $before = $this->cacheStore()->loadHttp();
        self::assertNotNull($before);

        $this->writePoisonedFixture();

        try {
            $command->run(CommandArguments::parse([]));
            self::fail('Expected the rebuild to fail against the poisoned fixture.');
        } catch (CacheWriteException) {
            // Expected — see writePoisonedFixture()'s own docblock.
        }

        // A fresh CacheStore, not one that could have pinned to
        // something stale from before the failed attempt.
        $after = (new CacheStore($this->projectRoot . '/.kinetis-cache'))->loadHttp();
        self::assertEquals($before, $after);
    }

    public function test_destroy_removes_the_cache_directory_without_rebuilding_it(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));
        self::assertTrue($this->cacheStore()->exists());

        $exitCode = $command->run(CommandArguments::parse(['--destroy']));

        self::assertSame(0, $exitCode);
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.kinetis-cache');
    }

    public function test_destroy_removes_every_retained_generation_too(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run(CommandArguments::parse([]));
        $firstGenerationDirectory = $this->cacheStore()->activeGenerationDirectory();
        self::assertNotNull($firstGenerationDirectory);
        $command->run(CommandArguments::parse([]));

        $command->run(CommandArguments::parse(['--destroy']));

        self::assertDirectoryDoesNotExist($firstGenerationDirectory);
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.kinetis-cache');
    }

    /**
     * A real, scannable PSR-4 project whose one route's #[Body] DTO has
     * a constructor default that is a live object — Compiler::
     * compileProject() reflects it via the real ReflectionParameter::
     * getDefaultValue() path (which genuinely returns the instantiated
     * object for a "new in initializers" default, not a compile-time
     * placeholder), producing a HydrationPlan CacheStore::writeAll()
     * cannot var_export() back. This is the real mechanism, not a
     * synthetic stand-in — the same one CacheStoreTest's own
     * poisonedCompiledCache() constructs directly, reached here through
     * genuine discovery instead.
     */
    private function writePoisonedFixture(): void
    {
        $namespace = 'Kinetis\\Tests\\Console\\BuildCommandPoisonedFixture';

        mkdir($this->projectRoot . '/src', 0775, true);

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ["{$namespace}\\" => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        // NamespaceScanner derives class *names* straight from
        // composer.json's own psr-4 map, independent of the real
        // Composer autoloader — but actually reflecting one of those
        // names (AttributeScope::isRegistrable(), which this discovery
        // pass calls on every candidate it finds) still needs PHP's own
        // autoloading to resolve it, and this ad-hoc fixture root was
        // never composer install'd, so nothing already knows about it.
        // Registering it directly on the real, already-active
        // ClassLoader is what a real project's own generated
        // vendor/autoload.php already does implicitly, since that's
        // generated from the identical composer.json — this line is the
        // test-only stand-in for that step.
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                $autoloader[0]->addPsr4("{$namespace}\\", $this->projectRoot . '/src');
            }
        }

        file_put_contents($this->projectRoot . '/src/PoisonedDto.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final readonly class PoisonedDto
            {
                public function __construct(
                    public \\DateTimeImmutable \$since = new \\DateTimeImmutable(),
                ) {}
            }
            PHP);

        file_put_contents($this->projectRoot . '/src/PoisonedController.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Kinetis\\Http\\Attributes\\Body;
            use Kinetis\\Http\\Attributes\\Post;

            final readonly class PoisonedController
            {
                #[Post('/poisoned')]
                public function create(#[Body] PoisonedDto \$dto): array
                {
                    return [];
                }
            }
            PHP);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Not glob('*') — the cache lives under the dot-directory
        // .kinetis-cache, which a bare "*" glob pattern never matches.
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $entry = $directory . '/' . $name;

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
            } else {
                unlink($entry);
            }
        }

        rmdir($directory);
    }
}
