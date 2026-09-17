<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Kinetis\McpProtocol\Exception\JsonRpcException;
use stdClass;

/**
 * What a consumer supplies to {@see McpServer}: the tools and resources it
 * publishes, and how a call or a read is answered.
 *
 * The server owns the whole protocol around this. It validates every
 * envelope and every parameter, resolves `name`/`uri` against the two
 * lists below, and never invokes a call for a name those lists do not
 * carry — so an implementation answers only for its own published
 * surface. It derives its advertised capabilities from those lists too:
 * a consumer publishing no tools advertises no `tools` capability.
 *
 * $context is whatever the adapter that invoked the server passed for
 * this one message, or null. The shared package never reads it, stores
 * it, or requires it to be anything: a consumer that needs a per-message
 * container, scope or unit of work supplies its own and knows its own
 * type back.
 */
interface McpApplication
{
    /**
     * The complete tool list, in publication order.
     *
     * @return list<ToolDescription>
     */
    public function tools(): array;

    /**
     * The complete resource list, in publication order.
     *
     * @return list<ResourceDescription>
     */
    public function resources(): array;

    /**
     * $name is always one of {@see tools()}'s own names. $arguments is the
     * call's own `arguments` object with its JSON provenance intact —
     * `stdClass` for every object and a PHP list for every array, at every
     * depth — so an implementation can still tell `{}` from `[]`. An
     * absent `arguments` arrives as an empty `stdClass`.
     *
     * A tool that ran and refused returns {@see ToolResult::error()}.
     * Throwing {@see JsonRpcException} answers with that protocol error
     * instead; any other exception is contained by the server as a
     * generic internal error, so an implementation that wants its own
     * diagnostics writes them itself.
     *
     * A result — or a thrown protocol error — whose text cannot be JSON
     * encoded is answered as that same generic internal error rather than
     * repaired: altered content under a successful result would be a
     * worse answer than an honest failure.
     */
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult;

    /**
     * $uri is always one of {@see resources()}'s own URIs. Failure
     * behavior matches {@see callTool()}, except that a read has no
     * error result of its own: a read that cannot be answered throws
     * {@see JsonRpcException}.
     */
    public function readResource(string $uri, ?object $context): ResourceResult;
}
