<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use JsonException;
use Kinetis\McpDocs\DocsCatalogue;
use Kinetis\McpDocs\DocsPage;
use Kinetis\McpDocs\McpDocsServer;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is written out by hand because this package ships no
 * copy of the documentation, so the pairing between it and the pages
 * themselves is what this file enforces: a page added to `docs/` without
 * an entry, or an entry naming a page that no longer exists, fails here
 * rather than reaching a client as a resource that reads nothing.
 *
 * These tests read the monorepo the package lives in. Its `tests/`
 * directory is export-ignored, so they never travel to the split
 * repository, where there is no `docs/` to compare against.
 */
final class DocsCatalogueTest extends TestCase
{
    private const string MONOREPO_ROOT = __DIR__ . '/../../..';

    public function test_the_catalogue_lists_every_documentation_page_and_no_others(): void
    {
        $docsDirectory = self::MONOREPO_ROOT . '/docs';

        self::assertDirectoryExists($docsDirectory);

        $files = glob($docsDirectory . '/*.md');

        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $onDisk = array_map(
            static fn (string $path): string => basename($path, '.md'),
            $files,
        );

        $catalogued = array_map(
            static fn (DocsPage $page): string => $page->slug,
            DocsCatalogue::pages(),
        );

        sort($onDisk);
        sort($catalogued);

        self::assertSame($onDisk, $catalogued);
    }

    public function test_every_entry_carries_a_name_and_a_description(): void
    {
        foreach (DocsCatalogue::pages() as $page) {
            self::assertNotSame('', $page->name, "{$page->slug} has no name.");
            self::assertNotSame('', $page->description, "{$page->slug} has no description.");
        }
    }

    public function test_slugs_are_unique(): void
    {
        $slugs = array_map(static fn (DocsPage $page): string => $page->slug, DocsCatalogue::pages());

        self::assertSame(count($slugs), count(array_unique($slugs)));
    }

    public function test_a_page_builds_its_own_uri_and_source_url_from_its_slug(): void
    {
        $page = new DocsPage('routing-validation', 'Routing & Validation', 'Attribute-based routes');

        self::assertSame('kinetis://docs/routing-validation', $page->uri());
        self::assertSame(
            'https://raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/routing-validation.md',
            $page->sourceUrl(),
        );
    }

    public function test_source_urls_are_https(): void
    {
        foreach (DocsCatalogue::pages() as $page) {
            self::assertStringStartsWith('https://', $page->sourceUrl());
        }
    }

    public function test_find_returns_the_page_a_uri_names(): void
    {
        $page = DocsCatalogue::find('kinetis://docs/tutorial');

        self::assertInstanceOf(DocsPage::class, $page);
        self::assertSame('tutorial', $page->slug);
    }

    public function test_find_returns_null_for_a_uri_the_catalogue_does_not_carry(): void
    {
        self::assertNull(DocsCatalogue::find('kinetis://docs/nothing-here'));
        self::assertNull(DocsCatalogue::find('file:///etc/passwd'));
    }

    /**
     * The version a client is told in `initialize` is the version this
     * package publishes.
     *
     * @throws JsonException
     */
    public function test_the_advertised_server_version_matches_the_manifest(): void
    {
        $manifestPath = self::MONOREPO_ROOT . '/packages.manifest.json';

        self::assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame($manifest['packages']['mcp-docs']['version'], McpDocsServer::SERVER_VERSION);
    }
}
