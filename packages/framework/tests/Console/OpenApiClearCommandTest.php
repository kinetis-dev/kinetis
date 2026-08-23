<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\OpenApiClearCommand;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use PHPUnit\Framework\TestCase;

final class OpenApiClearCommandTest extends TestCase
{
    public function test_removes_a_cached_document_and_reports_it(): void
    {
        $cache = new InMemorySimpleCache();
        $cache->set(DocumentationController::CACHE_KEY, ['openapi' => '3.1.0']);

        ob_start();
        $exitCode = new OpenApiClearCommand($cache)->run();
        $output = ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertIsString($output);
        self::assertStringContainsString('removed', $output);
        self::assertFalse($cache->has(DocumentationController::CACHE_KEY));
    }

    /**
     * A deployment runs this whether or not anything was cached — in
     * development nothing ever is — so an empty cache is a normal
     * outcome rather than an error.
     */
    public function test_reports_success_when_nothing_was_cached(): void
    {
        $cache = new InMemorySimpleCache();

        ob_start();
        $exitCode = new OpenApiClearCommand($cache)->run();
        $output = ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertIsString($output);
        self::assertStringContainsString('No cached OpenAPI document', $output);
    }

    /**
     * The command and the controller have to agree on the key, and
     * nothing but this shared constant makes them.
     */
    public function test_clears_the_key_the_controller_writes(): void
    {
        $cache = new InMemorySimpleCache();
        $cache->set(DocumentationController::CACHE_KEY, ['openapi' => '3.1.0']);
        $cache->set('unrelated.entry', 'kept');

        new OpenApiClearCommand($cache)->run();

        self::assertFalse($cache->has(DocumentationController::CACHE_KEY));
        self::assertSame('kept', $cache->get('unrelated.entry'));
    }
}
