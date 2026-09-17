<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * One resource as `resources/list` publishes it, and the URI a
 * `resources/read` names it by.
 */
final readonly class ResourceDescription
{
    public function __construct(
        public string $uri,
        public string $name,
        public string $description,
        public string $mimeType,
    ) {}
}
