<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

/**
 * One entry in DocsCatalogue: the documentation page's slug, the name
 * and description a client sees in `resources/list`, and the two
 * strings derived from the slug — the resource URI a client reads back
 * and the raw URL that page's markdown is fetched from.
 */
final readonly class DocsPage
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
    ) {}

    public function uri(): string
    {
        return DocsCatalogue::URI_PREFIX . $this->slug;
    }

    public function sourceUrl(): string
    {
        return DocsCatalogue::SOURCE_BASE_URL . $this->slug . '.md';
    }
}
