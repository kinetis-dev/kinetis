<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Closure;
use JsonException;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use stdClass;
use Throwable;

/**
 * MCP revision `2025-06-18` over JSON-RPC 2.0, against one {@see
 * McpApplication}. Transport-agnostic: {@see StdioLoop} feeds it one line
 * at a time and an HTTP controller feeds it one body at a time, and both
 * just encode whatever comes back.
 *
 * Stateless. It holds immutable server metadata and the consumer
 * reference, and nothing else — no negotiated version, no session, no
 * arguments, no progress token and no result survive a call. That is what
 * lets the same instance sit behind a persistent stdio process and behind
 * a stateless HTTP route at once. It follows that initialization ordering
 * is not enforced: a client is required to initialize first, and a server
 * that remembered whether it had would answer differently over HTTP,
 * where each request stands alone.
 *
 * `initialize` always selects `2025-06-18`, whichever revision the client
 * asked for. The specification's negotiation rule is that a server
 * answers with a version it supports and the client decides whether to
 * continue, so a single-version server has no unsupported-version error
 * to raise.
 *
 * A tool that ran and failed is an ordinary result carrying
 * `isError: true` — the MCP convention, so an agent sees "the tool ran and
 * refused" rather than a transport failure. Only a malformed request, an
 * unknown method, an unknown tool or resource name, or an unexpected
 * failure becomes a JSON-RPC error. An unexpected failure is contained
 * here as a generic -32603 whose message is fixed: a caught exception's
 * own text can carry SQL, a path or a credential, and a persistent stdio
 * process must not be crashed by one bad message either.
 *
 * Containment covers the response itself, not only the exceptions on the
 * way to it. Every envelope this returns — a dispatched result, a
 * protocol error, and a rejected envelope's own error alike — is checked
 * for encodability before it leaves, see {@see sendable()}, so nothing a
 * caller or a consumer supplies can hand a transport bytes that fail to
 * encode and end the process writing them.
 */
final readonly class McpServer implements MessageHandler
{
    /** The one MCP revision this server implements. */
    public const string PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private ServerInfo $serverInfo,
        private McpApplication $application,
    ) {}

    /**
     * @param array<string, mixed> $message
     * @param Closure(array<string, mixed>): void|null $emit
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function handle(array $message, ?Closure $emit = null, ?object $context = null): ?array
    {
        // Defends this public array boundary itself rather than trusting
        // that a caller already decoded through JsonRpcCodec — the same
        // rules either way, so a message built directly gets identical
        // treatment to one off the wire.
        $validated = JsonRpcCodec::validate($message);

        if (array_key_exists('errorResponse', $validated)) {
            // Through the same guard as a dispatched response: a
            // structural failure echoes the id it could read, and a
            // caller building a message in PHP can supply one that is
            // itself unencodable.
            $rejectedId = JsonRpcCodec::objectGet($validated['errorResponse'], 'id');

            return $this->sendable(
                $validated['errorResponse'],
                JsonRpcCodec::isValidId($rejectedId) ? $rejectedId : null,
            );
        }

        if (array_key_exists('ignored', $validated)) {
            return null;
        }

        $message = $validated['message'];

        // Every notification this revision defines from client to server
        // — `initialized`, `cancelled`, `progress`, `roots/list_changed` —
        // asks this server for nothing: it runs no long operation a client
        // can cancel, sends no request to report progress on, and reads no
        // roots. So a notification is answered with nothing and does
        // nothing, which is also what keeps a tool from being invoked for
        // a message whose caller could never read the result.
        if (!array_key_exists('id', $message)) {
            return null;
        }

        /** @var string|int $id validated above */
        $id = $message['id'];
        /** @var string $method validated above */
        $method = $message['method'];
        $params = $message['params'] ?? new stdClass();

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new stdClass(),
                'tools/list' => $this->listTools($params),
                'tools/call' => $this->callTool($params, $emit, $context),
                'resources/list' => $this->listResources($params),
                'resources/read' => $this->readResource($params, $context),
                default => throw JsonRpcException::methodNotFound($method),
            };
        } catch (JsonRpcException $e) {
            return $this->sendable(JsonRpcCodec::errorEnvelope($id, $e), $id);
        } catch (Throwable) {
            // Contained, and the caught text discarded: a consumer that
            // wants diagnostics writes its own before letting an exception
            // reach this boundary.
            return $this->sendable(JsonRpcCodec::errorEnvelope($id, JsonRpcException::internalError()), $id);
        }

        return $this->sendable(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $id);
    }

    /**
     * $response, or the generic internal error when it cannot be encoded.
     *
     * A consumer chooses the bytes in a tool result, a resource's text and
     * a `JsonRpcException`'s own message and data, and any of them can be
     * a string PHP accepts and JSON cannot — invalid UTF-8 most
     * obviously. Left to the transport, that becomes a `JsonException`
     * thrown while writing the frame, which ends a persistent stdio
     * process on one bad message and loses every message queued behind it.
     * So encodability is settled here, where the request id is still known
     * and a response can still be replaced.
     *
     * The replacement says only that the server failed: the bytes that
     * could not be encoded are the reason and never the payload. They are
     * not repaired either — substituting them would hand a client altered
     * content under a successful result, which is a worse answer than an
     * honest failure.
     *
     * The fallback to a null id covers the one case the generic error
     * cannot otherwise survive: an id is echoed verbatim, and a caller
     * building a message in PHP can supply one that is itself unencodable
     * (a decoded message never can, since JSON input is valid UTF-8 by
     * definition).
     *
     * $id is nullable because a rejected envelope often has no id worth
     * echoing, and that is the id such a response already carries.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function sendable(array $response, string|int|null $id): array
    {
        if (self::encodable($response)) {
            return $response;
        }

        $generic = JsonRpcCodec::errorEnvelope($id, JsonRpcException::internalError());

        return self::encodable($generic)
            ? $generic
            : JsonRpcCodec::errorEnvelope(null, JsonRpcException::internalError());
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function encodable(array $message): bool
    {
        try {
            json_encode($message, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(mixed $params): array
    {
        $version = JsonRpcCodec::objectGet($params, 'protocolVersion');

        if (!is_string($version) || $version === '') {
            throw JsonRpcException::invalidParams('The "protocolVersion" member must be a non-empty string.');
        }

        if (!JsonRpcCodec::isJsonObject(JsonRpcCodec::objectGet($params, 'capabilities'))) {
            throw JsonRpcException::invalidParams('The "capabilities" member is required and must be an object.');
        }

        $clientInfo = JsonRpcCodec::objectGet($params, 'clientInfo');

        if (!JsonRpcCodec::isJsonObject($clientInfo)) {
            throw JsonRpcException::invalidParams('The "clientInfo" member is required and must be an object.');
        }

        foreach (['name', 'version'] as $member) {
            $value = JsonRpcCodec::objectGet($clientInfo, $member);

            if (!is_string($value) || $value === '') {
                throw JsonRpcException::invalidParams(
                    "The \"clientInfo.{$member}\" member is required and must be a non-empty string.",
                );
            }
        }

        $capabilities = [];

        // Advertised from what the consumer actually publishes, so a
        // server offering only resources never invites a `tools/list`.
        if ($this->application->tools() !== []) {
            $capabilities['tools'] = new stdClass();
        }

        if ($this->application->resources() !== []) {
            $capabilities['resources'] = new stdClass();
        }

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => $capabilities === [] ? new stdClass() : $capabilities,
            'serverInfo' => ['name' => $this->serverInfo->name, 'version' => $this->serverInfo->version],
            ...($this->serverInfo->instructions !== null ? ['instructions' => $this->serverInfo->instructions] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(mixed $params): array
    {
        $this->rejectCursor($params);

        $tools = [];

        foreach ($this->application->tools() as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                // An empty schema array is a PHP list, which would encode
                // as `[]` where the protocol requires an object.
                'inputSchema' => $tool->inputSchema === [] ? new stdClass() : $tool->inputSchema,
                ...($tool->annotations !== null ? ['annotations' => [
                    'readOnlyHint' => $tool->annotations->readOnly,
                    'destructiveHint' => $tool->annotations->destructive,
                    'idempotentHint' => $tool->annotations->idempotent,
                    'openWorldHint' => $tool->annotations->openWorld,
                ]] : []),
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * @return array<string, mixed>
     */
    private function listResources(mixed $params): array
    {
        $this->rejectCursor($params);

        $resources = [];

        foreach ($this->application->resources() as $resource) {
            $resources[] = [
                'uri' => $resource->uri,
                'name' => $resource->name,
                'description' => $resource->description,
                'mimeType' => $resource->mimeType,
            ];
        }

        return ['resources' => $resources];
    }

    /**
     * Both lists are returned whole and no `nextCursor` is ever issued, so
     * any cursor a client sends is one this server did not give it.
     */
    private function rejectCursor(mixed $params): void
    {
        if (JsonRpcCodec::objectHas($params, 'cursor')) {
            throw JsonRpcException::invalidParams('Unknown cursor: this server returns its whole list at once.');
        }
    }

    /**
     * @param Closure(array<string, mixed>): void|null $emit
     * @return array<string, mixed>
     */
    private function callTool(mixed $params, ?Closure $emit, ?object $context): array
    {
        $name = JsonRpcCodec::objectGet($params, 'name');

        if (!is_string($name) || $name === '') {
            throw JsonRpcException::invalidParams('The "name" member is required and must be a non-empty string.');
        }

        $arguments = new stdClass();

        if (JsonRpcCodec::objectHas($params, 'arguments')) {
            $arguments = JsonRpcCodec::objectGet($params, 'arguments');

            if (!JsonRpcCodec::isJsonObject($arguments)) {
                throw JsonRpcException::invalidParams('The "arguments" member must be an object.');
            }
        }

        $token = $this->progressToken($params);

        foreach ($this->application->tools() as $tool) {
            if ($tool->name !== $name) {
                continue;
            }

            /** @var stdClass $converted toObjectTree() returns an object for any value isJsonObject() admitted */
            $converted = JsonRpcCodec::toObjectTree($arguments);
            $result = $this->application->callTool(
                $name,
                $converted,
                new ProgressEmitter($token === null ? null : $emit, $token),
                $context,
            );

            return [
                'content' => [['type' => 'text', 'text' => $result->text]],
                'isError' => $result->isError,
                // Cast because an empty document is a PHP list, which
                // would encode as `[]` where the protocol requires an
                // object; the text block stays for clients that read no
                // structured output.
                ...($result->structuredContent !== null
                    ? ['structuredContent' => (object) $result->structuredContent]
                    : []),
            ];
        }

        throw JsonRpcException::invalidParams("Unknown tool: \"{$name}\".");
    }

    /**
     * The call's own progress token, or null when it asked for no
     * progress. A present token of the wrong type is rejected rather than
     * silently disabling progress: a client that explicitly asked for it
     * cannot otherwise tell quiet non-reporting from a tool that never
     * reports.
     */
    private function progressToken(mixed $params): int|string|null
    {
        if (!JsonRpcCodec::objectHas($params, '_meta')) {
            return null;
        }

        $meta = JsonRpcCodec::objectGet($params, '_meta');

        if (!JsonRpcCodec::isJsonObject($meta)) {
            throw JsonRpcException::invalidParams('The "_meta" member must be an object.');
        }

        if (!JsonRpcCodec::objectHas($meta, 'progressToken')) {
            return null;
        }

        $token = JsonRpcCodec::objectGet($meta, 'progressToken');

        if (!is_string($token) && !is_int($token)) {
            throw JsonRpcException::invalidParams('The "progressToken" member must be a string or an integer.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function readResource(mixed $params, ?object $context): array
    {
        $uri = JsonRpcCodec::objectGet($params, 'uri');

        if (!is_string($uri) || $uri === '') {
            throw JsonRpcException::invalidParams('The "uri" member is required and must be a non-empty string.');
        }

        foreach ($this->application->resources() as $resource) {
            if ($resource->uri !== $uri) {
                continue;
            }

            $result = $this->application->readResource($uri, $context);

            return ['contents' => [[
                'uri' => $result->uri,
                'mimeType' => $result->mimeType,
                'text' => $result->text,
            ]]];
        }

        throw JsonRpcException::resourceNotFound($uri);
    }
}
