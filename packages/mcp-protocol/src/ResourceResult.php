<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * The text one `resources/read` returns, under the URI it was read by and
 * the media type that text is written in.
 */
final readonly class ResourceResult
{
    public function __construct(
        public string $uri,
        public string $mimeType,
        public string $text,
    ) {}
}
