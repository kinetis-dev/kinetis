<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\RoutesListCommand;
use Kinetis\Http\Middleware\ExceptionHandlerMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\DiscoveredGlobalMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\HighPriorityMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\RouteLevelMiddlewareA;
use Kinetis\Tests\Cache\Fixtures\Http\RouteLevelMiddlewareB;
use PHPUnit\Framework\TestCase;

final class RoutesListCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__) . '/Cache/Fixtures';
    }

    private function runCommand(?string $projectRoot = null): string
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $command = new RoutesListCommand($projectRoot ?? $this->projectRoot, $stream);
        $exitCode = $command->run();
        self::assertSame(0, $exitCode);

        rewind($stream);
        $output = stream_get_contents($stream);
        self::assertNotFalse($output);

        return $output;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $output): array
    {
        return explode("\n", $output);
    }

    private static function lineIndexContaining(array $lines, string $needle): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        self::fail("No line contains \"{$needle}\".\n\nOutput:\n" . implode("\n", $lines));
    }

    public function test_prints_the_global_middleware_pipeline_with_exception_handler_first(): void
    {
        $lines = self::lines($this->runCommand());

        self::assertStringContainsString('Global middleware (outermost to innermost):', $lines[0]);
        self::assertStringContainsString('1. ' . ExceptionHandlerMiddleware::class, $lines[1]);
    }

    public function test_higher_priority_discovered_middleware_prints_before_lower_priority(): void
    {
        $lines = self::lines($this->runCommand());

        // HighPriorityMiddleware (priority 10) must print before
        // DiscoveredGlobalMiddleware (priority 0) — the same order
        // GlobalMiddlewareDiscoveryTest already proves at the discovery
        // level, now proven end to end through the printed command output.
        $highPriorityLine = self::lineIndexContaining($lines, HighPriorityMiddleware::class);
        $defaultPriorityLine = self::lineIndexContaining($lines, DiscoveredGlobalMiddleware::class);

        self::assertLessThan($defaultPriorityLine, $highPriorityLine);
    }

    public function test_prints_every_discovered_route_with_its_method_path_status_and_controller(): void
    {
        $output = $this->runCommand();

        self::assertMatchesRegularExpression(
            '/GET\s+\/fixture-ping\s+200\s+Kinetis\\\\Tests\\\\Cache\\\\Fixtures\\\\Http\\\\DiscoveredPingController::ping/',
            $output,
        );
    }

    public function test_a_route_with_no_middleware_shows_a_placeholder(): void
    {
        $output = $this->runCommand();

        self::assertMatchesRegularExpression('/\/fixture-ping\s+200\s+\S+::ping\s+—/', $output);
    }

    public function test_a_routes_middleware_prints_class_level_then_method_level_in_order(): void
    {
        // Each middleware gets its own line — "->" trailing every line but
        // the last — rather than one long joined line, so a route stacking
        // several classes never forces an unreasonably wide table.
        $lines = self::lines($this->runCommand());
        $firstLine = self::lineIndexContaining($lines, '/fixture-with-middleware');

        self::assertStringEndsWith(RouteLevelMiddlewareA::class . ' ->', $lines[$firstLine]);
        self::assertStringEndsWith(RouteLevelMiddlewareB::class, $lines[$firstLine + 1]);

        // The continuation line's other four columns are blank, not a
        // repeat of the route's method/path/status/controller.
        self::assertStringNotContainsString('/fixture-with-middleware', $lines[$firstLine + 1]);
    }

    public function test_prints_a_message_when_no_routes_are_discovered(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/kinetis_routes_list_empty_' . bin2hex(random_bytes(8));
        mkdir($emptyRoot);

        try {
            self::assertStringContainsString('No routes discovered.', $this->runCommand($emptyRoot));
        } finally {
            rmdir($emptyRoot);
        }
    }

    public function test_explicitly_registered_middleware_is_not_duplicated(): void
    {
        $root = sys_get_temp_dir() . '/kinetis_routes_list_explicit_' . bin2hex(random_bytes(8));
        mkdir($root . '/src/Http', recursive: true);

        file_put_contents($root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]));

        // #[AsGlobalMiddleware] on a class that bootstrap.php *also*
        // registers explicitly — the exact scenario GlobalMiddlewareOrder
        // exists to dedupe.
        file_put_contents($root . '/src/Http/SharedMiddleware.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Http;
            use Kinetis\Http\Attributes\AsGlobalMiddleware;
            use Psr\Http\Message\ResponseInterface;
            use Psr\Http\Message\ServerRequestInterface;
            use Psr\Http\Server\MiddlewareInterface;
            use Psr\Http\Server\RequestHandlerInterface;
            #[AsGlobalMiddleware(priority: 100)]
            final class SharedMiddleware implements MiddlewareInterface
            {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return $handler->handle($request);
                }
            }
            PHP);

        file_put_contents($root . '/bootstrap.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use App\Http\SharedMiddleware;
            use Kinetis\Config\Config;
            use Kinetis\Container\AppScope;
            return static function (AppScope $app, Config $config): void {
                $app->middleware(SharedMiddleware::class);
            };
            PHP);

        try {
            $output = $this->runCommand($root);

            self::assertSame(1, substr_count($output, 'App\\Http\\SharedMiddleware'));
        } finally {
            unlink($root . '/composer.json');
            unlink($root . '/src/Http/SharedMiddleware.php');
            unlink($root . '/bootstrap.php');
            rmdir($root . '/src/Http');
            rmdir($root . '/src');
            rmdir($root);
        }
    }
}
