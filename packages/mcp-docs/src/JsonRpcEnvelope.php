<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use JsonException;
use Kinetis\McpDocs\Exception\JsonRpcException;
use stdClass;

/**
 * Decodes and structurally validates one line of stdin. Everything past
 * here works with a JsonRpcRequest, so McpDocsServer never re-checks an
 * envelope field.
 *
 * The decode is object mode, not associative: a JSON object becomes a
 * stdClass and a JSON array becomes a PHP array, which is what lets a
 * top-level array be recognized as a batch and rejected. Batching is
 * not implemented, and a batch carries no `id` to answer under, so it
 * is answered as an invalid request with a null id rather than mistaken
 * for a notification.
 */
final class JsonRpcEnvelope
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * @return array{request: JsonRpcRequest}|array{errorResponse: array<string, mixed>}
     */
    public static function decode(string $raw): array
    {
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::parseError())];
        }

        if (!$decoded instanceof stdClass) {
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::invalidRequest())];
        }

        $hasId = property_exists($decoded, 'id');
        $id = $hasId ? $decoded->id : null;

        // An id outside the string/int/null domain leaves nothing valid
        // to echo back, so the error answers under a null id — the same
        // rule JSON-RPC 2.0 states for an id that could not be read.
        if ($hasId && !is_string($id) && !is_int($id) && $id !== null) {
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::invalidRequest())];
        }

        /** @var string|int|null $id */
        if (!property_exists($decoded, 'jsonrpc') || $decoded->jsonrpc !== '2.0'
            || !property_exists($decoded, 'method') || !is_string($decoded->method)) {
            return ['errorResponse' => self::errorEnvelope($id, JsonRpcException::invalidRequest())];
        }

        // Present-but-null is not the same as absent: JSON-RPC 2.0
        // allows `params` to be omitted, never to be null. A JSON array
        // is a params shape JSON-RPC itself allows, but every method
        // here takes named parameters, so it is refused alongside a
        // scalar.
        $hasParams = property_exists($decoded, 'params');
        $params = $hasParams ? $decoded->params : null;

        if ($hasParams && !$params instanceof stdClass) {
            // A notification is answered with nothing, and JSON-RPC 2.0
            // holds that rule for a call that would have failed on its
            // params too. The envelope is intact, so the message stays
            // the notification it is and reaches McpDocsServer to be
            // dropped undispatched, rather than drawing a -32602 its
            // sender has no response to read.
            if (!$hasId) {
                return ['request' => new JsonRpcRequest($decoded->method, false, null, null)];
            }

            return ['errorResponse' => self::errorEnvelope(
                $id,
                JsonRpcException::invalidParams('The "params" member must be an object.'),
            )];
        }

        return ['request' => new JsonRpcRequest($decoded->method, $hasId, $id, $params)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorEnvelope(string|int|null $id, JsonRpcException $e): array
    {
        $error = ['code' => $e->rpcCode, 'message' => $e->getMessage()];

        if ($e->data !== null) {
            $error['data'] = $e->data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
