<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use Kinetis\McpDocs\Exception\DocsFetchException;
use Kinetis\McpDocs\Exception\JsonRpcException;
use stdClass;

/**
 * Answers one validated JSON-RPC message. The whole server is five
 * methods — `initialize`, `notifications/initialized`, `ping`,
 * `resources/list` and `resources/read` — because the catalogue is
 * fixed: there are no tools, no prompts, no subscriptions, and nothing
 * a client can change.
 *
 * A notification is never dispatched. JSON-RPC 2.0 gives its sender no
 * response, and the one notification a client sends here,
 * `notifications/initialized`, has nothing to do — so returning early
 * also means a notification can never make this server fetch a page
 * whose content nobody would receive.
 */
// Not `readonly`: $diagnostics holds a stream, and `resource` is not
// a type a property can declare, which a readonly property requires.
final class McpDocsServer
{
    public const string SERVER_NAME = 'kinetis-mcp-docs';

    /** Paired against this package's manifest version by the suite. */
    public const string SERVER_VERSION = '1.1.0';

    /**
     * Every protocol revision this server speaks, oldest first. The
     * negotiated version is the client's own when it appears here; when
     * it does not, the answer is the newest of these, which is what the
     * specification asks a server to offer instead of an error.
     *
     * @var list<string>
     */
    public const array PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    private const string INSTRUCTIONS = 'Resources are the pages of the Kinetis documentation site, '
        . 'served as their published markdown. Call resources/list for the catalogue, then resources/read '
        . 'with a page URI. Read these instead of answering about Kinetis from memory.';

    private readonly DocsFetcher $fetcher;

    /** @var resource|null */
    private $diagnostics;

    /**
     * @param resource|null $diagnostics stream a fetch failure's real
     *     reason is written to; null discards it. Never stdout, which
     *     carries nothing but JSON-RPC frames.
     */
    public function __construct(?DocsFetcher $fetcher = null, $diagnostics = null)
    {
        $this->fetcher = $fetcher ?? new DocsFetcher();
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return array<string, mixed>|null the response to write, or null
     *     for a notification
     */
    public function handle(JsonRpcRequest $request): ?array
    {
        if (!$request->isRequest) {
            return null;
        }

        try {
            return [
                'jsonrpc' => '2.0',
                'id' => $request->id,
                'result' => $this->dispatch($request->method, $request->params),
            ];
        } catch (JsonRpcException $e) {
            return JsonRpcEnvelope::errorEnvelope($request->id, $e);
        }
    }

    /**
     * `notifications/initialized` has no arm here on purpose: as a
     * request rather than a notification it is a method this server does
     * not have, and -32601 says exactly that.
     */
    private function dispatch(string $method, ?stdClass $params): stdClass
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new stdClass(),
            'resources/list' => $this->listResources($params),
            'resources/read' => $this->readResource($params),
            default => throw JsonRpcException::methodNotFound($method),
        };
    }

    private function initialize(?stdClass $params): stdClass
    {
        $requested = $params->protocolVersion ?? null;

        if (!is_string($requested) || $requested === '') {
            throw JsonRpcException::invalidParams('The "protocolVersion" member must be a non-empty string.');
        }

        $negotiated = in_array($requested, self::PROTOCOL_VERSIONS, true)
            ? $requested
            : self::PROTOCOL_VERSIONS[count(self::PROTOCOL_VERSIONS) - 1];

        return (object) [
            'protocolVersion' => $negotiated,
            // An empty `resources` object is the whole capability: the
            // catalogue is fixed, so there is no listChanged to announce
            // and no subscribe to honor.
            'capabilities' => (object) ['resources' => new stdClass()],
            'serverInfo' => (object) ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    private function listResources(?stdClass $params): stdClass
    {
        // The catalogue is one page and no `nextCursor` is ever
        // returned, so any cursor a client sends is one this server did
        // not issue.
        if ($params !== null && property_exists($params, 'cursor')) {
            throw JsonRpcException::invalidParams('Unknown cursor: this server returns its whole catalogue at once.');
        }

        $resources = [];

        foreach (DocsCatalogue::pages() as $page) {
            $resources[] = [
                'uri' => $page->uri(),
                'name' => $page->name,
                'description' => $page->description,
                'mimeType' => DocsCatalogue::MIME_TYPE,
            ];
        }

        return (object) ['resources' => $resources];
    }

    private function readResource(?stdClass $params): stdClass
    {
        $uri = $params->uri ?? null;

        if (!is_string($uri) || $uri === '') {
            throw JsonRpcException::invalidParams('The "uri" member must be a non-empty string.');
        }

        $page = DocsCatalogue::find($uri);

        if ($page === null) {
            throw JsonRpcException::resourceNotFound($uri);
        }

        try {
            $text = $this->fetcher->fetch($page);
        } catch (DocsFetchException $e) {
            $this->reportDiagnostic($e->getMessage());

            throw JsonRpcException::internalError("Could not read \"{$uri}\".");
        }

        // A page that is not valid UTF-8 cannot be put into a JSON frame
        // at all, so it is rejected here rather than left to break the
        // encode of a response already declared successful.
        if (preg_match('//u', $text) !== 1) {
            $this->reportDiagnostic("{$page->sourceUrl()} is not valid UTF-8.");

            throw JsonRpcException::internalError("Could not read \"{$uri}\".");
        }

        return (object) [
            'contents' => [[
                'uri' => $page->uri(),
                'mimeType' => DocsCatalogue::MIME_TYPE,
                'text' => $text,
            ]],
        ];
    }

    private function reportDiagnostic(string $message): void
    {
        if ($this->diagnostics === null) {
            return;
        }

        fwrite($this->diagnostics, self::SERVER_NAME . ": {$message}\n");
    }
}
