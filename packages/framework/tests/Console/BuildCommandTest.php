<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Exception\UnexportableArtifactException;
use Kinetis\Console\BuildCommand;
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

    public function test_writes_a_loadable_artifact_and_returns_success(): void
    {
        $exitCode = new BuildCommand(projectRootOverride: $this->projectRoot)->run();

        self::assertSame(0, $exitCode);

        $loaded = $this->cacheStore()->load();
        self::assertNotNull($loaded);
    }

    /**
     * The artifact is an output of this command, never an input to it: a
     * second build compiles from source again and replaces the file in
     * place, rather than reading back what the first one left.
     */
    public function test_a_second_build_replaces_the_artifact_in_place(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run();

        self::assertSame(0, $command->run());

        // One file, still the artifact itself: no second copy beside it,
        // and no staged temporary file left over.
        self::assertSame([$this->cacheStore()->path()], glob($this->projectRoot . '/.kinetis-cache/*') ?: []);
        self::assertNotNull($this->cacheStore()->load());
    }

    /**
     * A rebuild that fails partway through compiling or writing leaves
     * whatever was already published exactly as it was. A real DTO whose
     * constructor default is a live object (PHP 8.1's "new in
     * initializers") is the genuine way a compile pass produces
     * something that cannot be var_export()ed back — the same mechanism
     * CacheStoreTest exercises directly against CacheStore, reached here
     * through the actual BuildCommand a deploy pipeline runs.
     */
    public function test_a_failed_rebuild_leaves_the_published_artifact_loadable_and_unchanged(): void
    {
        $command = new BuildCommand(projectRootOverride: $this->projectRoot);
        $command->run();

        $before = $this->cacheStore()->load();
        self::assertNotNull($before);

        $this->writePoisonedFixture();

        try {
            $command->run();
            self::fail('Expected the rebuild to fail against the poisoned fixture.');
        } catch (UnexportableArtifactException) {
            // Expected — see writePoisonedFixture()'s own docblock.
        }

        $after = (new CacheStore($this->projectRoot . '/.kinetis-cache'))->load();
        self::assertEquals($before, $after);
        self::assertSame([], glob($this->projectRoot . '/.kinetis-cache/*.tmp') ?: []);
    }

    /**
     * A real, scannable PSR-4 project whose one route's #[Body] DTO has
     * a constructor default that is a live object — Compiler::
     * compileProject() reflects it via the real ReflectionParameter::
     * getDefaultValue() path (which genuinely returns the instantiated
     * object for a "new in initializers" default, not a compile-time
     * placeholder), producing a HydrationPlan CacheStore::write()
     * refuses. This is the real mechanism, not a synthetic stand-in —
     * the same one CacheStoreTest's own poisonedCompiledCache()
     * constructs directly, reached here through genuine discovery
     * instead.
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
