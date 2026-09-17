<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * The 2025-06-18 tool annotations, as hints a client may use to decide
 * how much it asks of a human before a call.
 *
 * All four are stated together because a partial set is the one that
 * misleads: a tool that omits `destructiveHint` while declaring
 * `readOnlyHint: false` reads as the specification's default, which is
 * destructive. A tool declaring no annotations at all omits this object
 * entirely rather than guessing.
 */
final readonly class ToolAnnotations
{
    public function __construct(
        public bool $readOnly,
        public bool $destructive,
        public bool $idempotent,
        public bool $openWorld,
    ) {}
}
