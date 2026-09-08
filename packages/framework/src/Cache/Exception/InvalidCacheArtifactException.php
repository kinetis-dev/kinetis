<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use RuntimeException;

/**
 * A section of the compiled artifact does not represent a valid
 * instance of the type being reconstructed from it — a missing or
 * wrong-typed top-level field, or a malformed entry within one (a route
 * missing its `httpMethod`, say). Always means the artifact is stale or
 * corrupt, never a defect in the code reconstructing it.
 */
final class InvalidCacheArtifactException extends RuntimeException implements CacheArtifactExceptionInterface
{
    public static function missingField(string $type, string $field): self
    {
        return new self("A compiled \"{$type}\" artifact is missing its \"{$field}\" field — the cache is stale or corrupt.");
    }

    public static function wrongFieldType(string $type, string $field, string $expected): self
    {
        return new self("A compiled \"{$type}\" artifact's \"{$field}\" field is not {$expected} — the cache is stale or corrupt.");
    }

    public static function malformedEntry(string $type, string $context): self
    {
        return new self("A compiled \"{$type}\" artifact has a malformed entry ({$context}) — the cache is stale or corrupt.");
    }
}
