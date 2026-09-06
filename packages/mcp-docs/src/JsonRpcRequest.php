<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use stdClass;

/**
 * One JSON-RPC 2.0 message that has already passed JsonRpcEnvelope's
 * structural checks. `$isRequest` is what separates a request from a
 * notification — a notification carries no `id` at all, which is not
 * the same as carrying a null one, and only the first of those gets a
 * response.
 *
 * `$params` stays a stdClass rather than being flattened to an array:
 * `{}` and `[]` are different values on the wire, and PHP's associative
 * decode mode spells both `[]`.
 */
final readonly class JsonRpcRequest
{
    public function __construct(
        public string $method,
        public bool $isRequest,
        public string|int|null $id,
        public ?stdClass $params,
    ) {}
}
