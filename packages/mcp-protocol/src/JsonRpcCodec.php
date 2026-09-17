<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Kinetis\McpProtocol\Exception\JsonRpcException;
use stdClass;

/**
 * The one JSON-RPC 2.0 decode and structural-validation path every entry
 * point shares — the stdio loop, an HTTP controller, and {@see
 * McpServer::handle()} itself for a message an embedder built directly.
 * Each feeds a message through here before anything dispatches on
 * `method`, so a malformed envelope gets identical code and id semantics
 * whichever one received it.
 *
 * decode() uses `json_decode()`'s default object mode: a JSON object
 * becomes a `stdClass` and a JSON array becomes a plain PHP array, never
 * both the same PHP shape. That fidelity is kept in the message returned
 * rather than flattened, because `{}` and `[]` are different values on
 * the wire — an empty object is a valid `params`/`_meta`/
 * `arguments`, an empty array is not — and PHP's associative decode mode
 * collapses both to the identical `[]`. A tool's `arguments` reaches its
 * consumer still carrying that distinction at every depth.
 *
 * A caller building a message directly cannot write that distinction in a
 * PHP array literal, so {@see JsonObject} is accepted wherever an object
 * is required and {@see toObjectTree()} converts it back before a
 * consumer sees it.
 */
final class JsonRpcCodec
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * Decodes one raw message and validates its envelope.
     *
     * @return array{message: array<string, mixed>}|array{errorResponse: array<string, mixed>}|array{ignored: true}
     */
    public static function decode(string $raw): array
    {
        $decoded = json_decode($raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::parseError())];
        }

        return self::validate($decoded);
    }

    /**
     * Structural validation only: whether $message is a well-formed
     * JSON-RPC 2.0 message this server may dispatch, answer with an
     * error, or ignore. Never touches what `method` means.
     *
     * The three outcomes are distinct facts, not one nullable answer. A
     * `message` dispatches. An `errorResponse` is sent whether or not the
     * sender wanted one, because a broken envelope is exactly the thing
     * that would have told us it was a notification. `ignored` is the
     * message that must neither dispatch nor be answered: a notification
     * whose own params are malformed, which JSON-RPC 2.0 leaves its
     * sender unable to read a response to anyway, and a client response
     * message, which this server never elicits because it sends no
     * requests of its own.
     *
     * @return array{message: array<string, mixed>}|array{errorResponse: array<string, mixed>}|array{ignored: true}
     */
    public static function validate(mixed $message): array
    {
        if (!self::isJsonObject($message)) {
            // A bare scalar, null, or a top-level array — which object
            // mode has already made a plain PHP array, so this one check
            // covers a batch too. Batching is not part of this revision,
            // and a batch carries no id to answer under regardless.
            return self::invalid(null);
        }

        if (self::objectGet($message, 'jsonrpc') !== '2.0') {
            return self::invalid(self::safeId($message));
        }

        $idPresent = self::objectHas($message, 'id');
        $id = self::objectGet($message, 'id');
        $idValid = $idPresent && self::isValidId($id);

        if (!self::objectHas($message, 'method')) {
            return self::responseOrInvalid($message, $idValid);
        }

        $method = self::objectGet($message, 'method');

        if (!is_string($method) || $method === '') {
            return self::invalid(self::safeId($message));
        }

        // An id present but outside the admitted domain leaves nothing
        // valid to echo, so the error answers under a null id.
        if ($idPresent && !$idValid) {
            return self::invalid(null);
        }

        if (self::objectHas($message, 'params') && !self::isJsonObject(self::objectGet($message, 'params'))) {
            return $idPresent
                ? ['errorResponse' => self::errorEnvelope(
                    $id,
                    JsonRpcException::invalidParams('The "params" member must be an object.'),
                )]
                : ['ignored' => true];
        }

        return ['message' => [
            'jsonrpc' => '2.0',
            'method' => $method,
            ...($idPresent ? ['id' => $id] : []),
            // Deliberately not flattened — see this class's own docblock.
            ...(self::objectHas($message, 'params') ? ['params' => self::objectGet($message, 'params')] : []),
        ]];
    }

    /**
     * A message with no `method` is a response to a request — which this
     * server never sends, so a well-formed one is ignored rather than
     * answered. "Well-formed" is a valid id plus exactly one of `result`
     * and `error`; anything else is an invalid request.
     *
     * @return array{errorResponse: array<string, mixed>}|array{ignored: true}
     */
    private static function responseOrInvalid(mixed $message, bool $idValid): array
    {
        $hasResult = self::objectHas($message, 'result');
        $hasError = self::objectHas($message, 'error');

        if ($idValid && $hasResult !== $hasError) {
            return ['ignored' => true];
        }

        return self::invalid(self::safeId($message));
    }

    /**
     * @return array{errorResponse: array<string, mixed>}
     */
    private static function invalid(mixed $id): array
    {
        return ['errorResponse' => self::errorEnvelope($id, JsonRpcException::invalidRequest())];
    }

    /**
     * The id to answer an invalid request under: the message's own when
     * it is one this revision admits, and null otherwise.
     */
    private static function safeId(mixed $message): string|int|null
    {
        $id = self::objectGet($message, 'id');

        return self::isValidId($id) ? $id : null;
    }

    /**
     * The JSON-RPC id domain MCP 2025-06-18 admits: a string or an
     * integer. Null is excluded — the revision states an id MUST NOT be
     * null — and so are booleans, floats and structured values, which are
     * rejected rather than coerced.
     *
     * @phpstan-assert-if-true string|int $value
     */
    public static function isValidId(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    /**
     * True only for a value that unambiguously represents a JSON object: a
     * `stdClass`, a {@see JsonObject}, or a non-empty PHP array that is
     * not a list (a real string key makes it unambiguous on its own). A
     * bare `[]` is not accepted — see this class's own docblock.
     */
    public static function isJsonObject(mixed $value): bool
    {
        if ($value instanceof stdClass || $value instanceof JsonObject) {
            return true;
        }

        return is_array($value) && $value !== [] && !array_is_list($value);
    }

    /**
     * Reads $key off $node — a `stdClass`, a {@see JsonObject} or a plain
     * array — or null when $node is none of those or does not carry $key.
     */
    public static function objectGet(mixed $node, string $key): mixed
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        }

        if ($node instanceof stdClass) {
            return $node->{$key} ?? null;
        }

        return is_array($node) ? ($node[$key] ?? null) : null;
    }

    /**
     * True when $node carries $key at all — `property_exists()`/
     * `array_key_exists()` semantics, not a non-null check, so a
     * present-but-null value is detected as present and still rejected
     * wherever an object is required.
     */
    public static function objectHas(mixed $node, string $key): bool
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        }

        if ($node instanceof stdClass) {
            return property_exists($node, $key);
        }

        return is_array($node) && array_key_exists($key, $node);
    }

    /**
     * The tree a consumer receives: every {@see JsonObject} marker becomes
     * the `stdClass` a decoded `{}` would have been, recursively, and
     * every other node is left exactly as it is. A message decoded off the
     * wire passes through unchanged; one built directly in PHP arrives in
     * the same shape it would have had on the wire.
     */
    public static function toObjectTree(mixed $node): mixed
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        } elseif ($node instanceof stdClass) {
            $node = get_object_vars($node);
        } elseif (is_array($node)) {
            return array_map(self::toObjectTree(...), $node);
        } else {
            return $node;
        }

        return (object) array_map(self::toObjectTree(...), $node);
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorEnvelope(mixed $id, JsonRpcException $e): array
    {
        $error = ['code' => $e->rpcCode, 'message' => $e->getMessage()];

        if ($e->data !== null) {
            $error['data'] = $e->data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
