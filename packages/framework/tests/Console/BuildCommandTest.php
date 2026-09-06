<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Cache\CacheStore;
use Kinetis\Console\BuildCommand;
use Kinetis\Reflection\Exception\UnsupportedDefaultValueException;
use Kinetis\Validation\Hydrator;
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
     * The default a plan may carry, through the command a deploy
     * pipeline actually runs: an enum case compiles into the hydration
     * plan, is written as a `var_export()` literal, and comes back out
     * of the published file as the same case — which is what makes the
     * hydration below produce it without the DTO's own default ever
     * being evaluated again.
     */
    public function test_a_build_carries_an_enum_case_default_through_the_published_artifact(): void
    {
        $namespace = $this->writeEnumDefaultFixture();

        self::assertSame(0, new BuildCommand(projectRootOverride: $this->projectRoot)->run());

        $plan = $this->cacheStore()->load()?->http->hydrationPlans["{$namespace}\\SearchRequest"];

        self::assertNotNull($plan);

        $dto = Hydrator::hydrate("{$namespace}\\SearchRequest", ['term' => 'kinetis'], $plan);

        self::assertSame(constant("{$namespace}\\SortDirection::Descending"), $dto->direction);
    }

    /**
     * A rebuild that fails leaves whatever was already published exactly
     * as it was. A real DTO whose constructor default is a live object
     * (PHP 8.1's "new in initializers") is the genuine way a compile
     * pass reaches a value no plan may carry, refused by
     * Kinetis\Reflection\ParameterDefault as the plan is derived —
     * reached here through the actual BuildCommand a deploy pipeline
     * runs, so the build a developer runs and the first request a
     * worker serves fail on the same declaration.
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
        } catch (UnsupportedDefaultValueException) {
            // Expected — see writePoisonedFixture()'s own docblock.
        }

        $after = (new CacheStore($this->projectRoot . '/.kinetis-cache'))->load();
        self::assertEquals($before, $after);
        self::assertSame([], glob($this->projectRoot . '/.kinetis-cache/*.tmp') ?: []);
    }

    /**
     * The enum-case sibling of writePoisonedFixture(): the same real
     * discovery path, declaring the one object default a plan carries.
     *
     * @return string the namespace its classes live in
     */
    private function writeEnumDefaultFixture(): string
    {
        $namespace = self::scannableProject($this->projectRoot, 'BuildCommandEnumFixture');

        file_put_contents($this->projectRoot . '/src/SortDirection.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            enum SortDirection: string
            {
                case Ascending = 'asc';

                case Descending = 'desc';
            }
            PHP);

        file_put_contents($this->projectRoot . '/src/SearchRequest.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final readonly class SearchRequest
            {
                public function __construct(
                    public string \$term,
                    public SortDirection \$direction = SortDirection::Descending,
                ) {}
            }
            PHP);

        file_put_contents($this->projectRoot . '/src/SearchController.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Kinetis\\Http\\Attributes\\Body;
            use Kinetis\\Http\\Attributes\\Post;

            final readonly class SearchController
            {
                #[Post('/search')]
                public function search(#[Body] SearchRequest \$request): array
                {
                    return ['direction' => \$request->direction->value];
                }
            }
            PHP);

        return $namespace;
    }

    /**
     * A real, scannable PSR-4 project whose one route's #[Body] DTO has
     * a constructor default that is a live object — Compiler::
     * compileProject() reflects it via the real ReflectionParameter::
     * getDefaultValue() path (which genuinely returns the instantiated
     * object for a "new in initializers" default, not a compile-time
     * placeholder), so the derivation refuses it. This is the real
     * mechanism, not a synthetic stand-in, reached through genuine
     * discovery.
     */
    private function writePoisonedFixture(): void
    {
        $namespace = self::scannableProject($this->projectRoot, 'BuildCommandPoisonedFixture');

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

    /**
     * A real, scannable PSR-4 project root: composer.json's own psr-4
     * map, plus the src/ directory the files below go into.
     *
     * NamespaceScanner derives class *names* straight from that map,
     * independent of the real Composer autoloader — but actually
     * reflecting one of those names (AttributeScope::isRegistrable(),
     * which this discovery pass calls on every candidate it finds) still
     * needs PHP's own autoloading to resolve it, and an ad-hoc fixture
     * root was never composer install'd, so nothing already knows about
     * it. Registering it on the real, already-active ClassLoader is what
     * a real project's generated vendor/autoload.php does implicitly,
     * being generated from the identical composer.json — the loop below
     * is the test-only stand-in for that step.
     *
     * @return string the fully-qualified namespace the fixture files declare
     */
    private static function scannableProject(string $projectRoot, string $name): string
    {
        $namespace = 'Kinetis\\Tests\\Console\\' . $name;

        mkdir($projectRoot . '/src', 0775, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ["{$namespace}\\" => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                $autoloader[0]->addPsr4("{$namespace}\\", $projectRoot . '/src');
            }
        }

        return $namespace;
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
