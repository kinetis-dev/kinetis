<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * One tool as `tools/list` publishes it. A wire description, not a
 * discovery record: nothing here says where the implementation lives or
 * how a consumer routes a call to it.
 */
final readonly class ToolDescription
{
    /**
     * @param array<string, mixed> $inputSchema the JSON Schema for the
     *        call's `arguments` object; an empty array is published as
     *        the empty JSON object `{}`
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ?ToolAnnotations $annotations = null,
    ) {}
}
