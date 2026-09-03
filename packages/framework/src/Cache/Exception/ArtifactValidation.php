<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

/**
 * @internal Shared field-extraction helpers for a compiled artifact's
 * own `fromArray()` — every one throws `InvalidCacheArtifactException`
 * for a missing or wrong-typed field, rather than letting a raw
 * missing-array-key warning, followed by a `TypeError` several calls
 * deeper (once the resulting `null` reaches a non-nullable typed
 * constructor parameter), escape from inside construction. Not part of
 * any class's own public contract — each `fromArray()` using this is
 * what actually declares `@throws CacheArtifactExceptionInterface`.
 */
final class ArtifactValidation
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $type, string $field): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'a string');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $type, string $field): int
    {
        $value = $data[$field] ?? null;

        if (!is_int($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'an integer');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string $type, string $field): ?string
    {
        $value = $data[$field] ?? null;

        if ($value !== null && !is_string($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'a string or null');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $type, string $field): bool
    {
        $value = $data[$field] ?? null;

        if (!is_bool($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'a boolean');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $type, string $field): array
    {
        $value = $data[$field] ?? null;

        if (!is_array($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'an array');
        }

        return $value;
    }

    /**
     * `array()` above, plus every element must itself be an array — the
     * shape every entry list (routes, commands) in this codebase uses.
     *
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function listOfArrays(array $data, string $type, string $field): array
    {
        $value = self::array($data, $type, $field);

        if (!array_is_list($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'a list');
        }

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw InvalidCacheArtifactException::malformedEntry($type, "a non-array entry in \"{$field}\"");
            }
        }

        /** @var list<array<array-key, mixed>> $value */
        return $value;
    }

    /**
     * `array()` above, plus every element must itself be a string — the
     * shape every declared class-string list (global/OpenAPI middleware,
     * package bootstraps, a route's own middleware references, a
     * channel's placeholder names) uses.
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function listOfStrings(array $data, string $type, string $field): array
    {
        $value = self::array($data, $type, $field);

        if (!array_is_list($value)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'a list');
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw InvalidCacheArtifactException::malformedEntry($type, "a non-string entry in \"{$field}\"");
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * A string-keyed map whose every value is itself a `list<string>` —
     * the shape `HttpCache::$middlewareGroups` uses. Every key must be a
     * real string too, the same numeric-array-key footgun `listOfStrings()`'s
     * siblings across this codebase already guard against.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, list<string>>
     */
    public static function mapOfListOfStrings(array $data, string $type, string $field): array
    {
        $value = self::array($data, $type, $field);
        $result = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw InvalidCacheArtifactException::malformedEntry($type, "a non-string key in \"{$field}\"");
            }

            if (!is_array($entry) || !array_is_list($entry)) {
                throw InvalidCacheArtifactException::malformedEntry($type, "the entry for \"{$key}\" in \"{$field}\" is not a list");
            }

            foreach ($entry as $item) {
                if (!is_string($item)) {
                    throw InvalidCacheArtifactException::malformedEntry($type, "a non-string entry for \"{$key}\" in \"{$field}\"");
                }
            }

            /** @var list<string> $entry */
            $result[$key] = $entry;
        }

        return $result;
    }

    /**
     * The `list<array{class: class-string, args: array<int|string, mixed>}>`
     * shape every constraint-descriptor list in this codebase uses
     * (`Dispatcher`'s `HttpBindingPlan`, `Hydrator`'s
     * `HydrationPlanParameter`) — validated once, shared by both owning
     * abstractions, rather than duplicated in each. `args`' own contents
     * are never validated beyond "an array": the specific constraint
     * class's own constructor signature is what actually gives them
     * meaning, and re-deriving every constraint class's own argument
     * shape here would mean this helper knowing about every constraint
     * class that will ever exist, including ones a consumer application
     * defines itself.
     *
     * @param array<array-key, mixed> $data
     * @return list<array{class: class-string, args: array<int|string, mixed>}>
     */
    public static function listOfConstraintDescriptors(array $data, string $type, string $field): array
    {
        $value = self::listOfArrays($data, $type, $field);
        $result = [];

        foreach ($value as $entry) {
            self::exactKeys($entry, "{$type} constraint", ['class', 'args']);

            $class = self::string($entry, "{$type} constraint", 'class');
            $args = self::array($entry, "{$type} constraint", 'args');

            /** @var class-string $class */
            $result[] = ['class' => $class, 'args' => $args];
        }

        return $result;
    }

    /**
     * Rejects any key the given entry carries beyond `$expectedKeys` (in
     * either direction — missing or extra), enforcing the *exact*
     * shape `toArray()` itself produces rather than merely tolerating
     * whatever fields happen to be present. Field-presence/type
     * mismatches are already caught by the `string()`/`int()`/etc.
     * helpers above; this catches the complementary case — an entry
     * carrying a field none of them ever asked for, which those helpers
     * alone would silently ignore.
     *
     * @param array<array-key, mixed> $entry
     * @param list<string> $expectedKeys
     */
    public static function exactKeys(array $entry, string $type, array $expectedKeys): void
    {
        $actual = array_keys($entry);
        sort($actual);
        sort($expectedKeys);

        if ($actual !== $expectedKeys) {
            throw InvalidCacheArtifactException::malformedEntry($type, 'an unexpected or missing field');
        }
    }
}
