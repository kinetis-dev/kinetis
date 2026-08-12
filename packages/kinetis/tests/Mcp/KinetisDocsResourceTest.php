<?php

declare(strict_types=1);

namespace Kinetis\Tests\Mcp;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Exception\DocsResourceException;
use Kinetis\Mcp\KinetisDocsResource;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KinetisDocsResourceTest extends TestCase
{
    private function server(): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(KinetisDocsResource::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app));
    }

    /**
     * @return list<string>
     */
    private function docSlugs(): array
    {
        $files = glob(dirname(__DIR__, 4) . '/docs/*.md');
        self::assertNotFalse($files);

        return array_values(array_map(
            static fn (string $path): string => basename($path, '.md'),
            $files,
        ));
    }

    public function test_every_real_docs_page_has_a_matching_resource_method(): void
    {
        $reflection = new ReflectionClass(KinetisDocsResource::class);
        $registeredUris = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(McpResource::class) as $attribute) {
                $registeredUris[] = $attribute->newInstance()->uri;
            }
        }

        foreach ($this->docSlugs() as $slug) {
            self::assertContains(
                "kinetis://docs/{$slug}",
                $registeredUris,
                "docs/{$slug}.md has no matching #[McpResource] on KinetisDocsResource.",
            );
        }
    }

    public function test_reading_a_resource_returns_the_actual_file_content(): void
    {
        $resource = new KinetisDocsResource();

        self::assertSame(
            file_get_contents(dirname(__DIR__, 4) . '/docs/getting-started.md'),
            $resource->gettingStarted(),
        );
    }

    /**
     * Every one of the 31 #[McpResource] methods is the exact same
     * one-line delegate to read() — testing only one (above) can't catch a
     * typo'd slug in any of the other 30. Invoking every one directly,
     * rather than only through the structural (attribute-only) checks the
     * other tests here already do, is what actually proves each page's own
     * content is reachable.
     */
    public function test_every_resource_method_returns_its_own_real_file_content(): void
    {
        $resource = new KinetisDocsResource();
        $reflection = new ReflectionClass($resource);

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(McpResource::class);

            if ($attributes === []) {
                continue;
            }

            $slug = str_replace('kinetis://docs/', '', $attributes[0]->newInstance()->uri);

            self::assertSame(
                file_get_contents(dirname(__DIR__, 4) . "/docs/{$slug}.md"),
                $method->invoke($resource),
                "{$method->getName()}() did not return docs/{$slug}.md's real content.",
            );
        }
    }

    public function test_resources_list_includes_every_docs_page(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list']);

        $uris = array_column($response['result']['resources'], 'uri');

        foreach ($this->docSlugs() as $slug) {
            self::assertContains("kinetis://docs/{$slug}", $uris);
        }
    }

    public function test_resources_read_returns_real_markdown_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://docs/getting-started'],
        ]);

        self::assertSame('text/markdown', $response['result']['contents'][0]['mimeType']);
        self::assertStringContainsString('# Getting Started', $response['result']['contents'][0]['text']);
    }

    /**
     * read() is private and every one of the 31 public methods hardcodes a
     * real, always-present slug, so the missing-page branch has no path
     * through the real public API at all — invoked directly via reflection
     * instead, the same technique this class's own first test already uses,
     * rather than deleting a real docs file mid-test.
     */
    public function test_reading_a_missing_page_throws_a_clear_error(): void
    {
        $resource = new KinetisDocsResource();
        $method = (new ReflectionClass($resource))->getMethod('read');

        $this->expectException(DocsResourceException::class);
        $this->expectExceptionMessage('Kinetis docs page "does-not-exist" is missing');

        // file_get_contents() on a nonexistent path emits a real PHP
        // warning before returning false — exactly the signal read()'s own
        // check is designed to catch. Expected here, not suppressed in
        // production code, just at this one deliberate call site.
        @$method->invoke($resource, 'does-not-exist');
    }
}
