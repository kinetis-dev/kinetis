<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use JsonException;

/**
 * Decodes a JSON object out of untrusted bytes under caller-stated
 * limits, rejecting any document that names the same member twice
 * inside a single object.
 *
 * json_decode() keeps the last of two identically-named members and
 * reports nothing, so `{"alg":"RS256","alg":"none"}` reaches a caller
 * as one value the parser picked rather than the one the sender stated.
 * A repeated member name is refused at every depth instead. Names are
 * compared decoded, so a `\uXXXX` spelling is caught as the same member
 * as its plain form.
 *
 * @internal Boundary helper for ParsedJwkSet and JoseHeader.
 */
final class StrictJson
{
    /**
     * Prefixes a member name before it becomes a PHP array key, so one
     * in canonical decimal form stays the string the document spelled.
     */
    private const string NAME_PREFIX = 'member#';

    /**
     * Returns the decoded object as an associative array, or null when
     * the input is empty, longer than $maximumBytes, not a JSON object
     * at the root, deeper than $maximumDepth, invalid JSON, or names
     * one member twice.
     *
     * $maximumDepth is a nesting limit json_decode() itself refuses
     * below 1, so the bound is stated on the parameter and every caller
     * is held to it — this class's own callers each pass a fixed
     * constant — rather than re-checked here against input no sender
     * reaches.
     *
     * @param int<1, max> $maximumDepth
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodeObject(
        #[\SensitiveParameter] string $json,
        int $maximumBytes,
        int $maximumDepth,
    ): ?array {
        if ($json === '' || strlen($json) > $maximumBytes) {
            return null;
        }

        // JSON's own whitespace set is exactly space, tab, LF and CR.
        if (!str_starts_with(ltrim($json, " \t\n\r"), '{')) {
            return null;
        }

        if (self::hasRepeatedMember($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, $maximumDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Scans the raw document for a member name repeated within one
     * object. Grammar validation stays json_decode()'s job; anything
     * this scan cannot make sense of counts as a repeat, so its own
     * bookkeeping can never drift into a false negative.
     *
     * Only the innermost container is ever updated, and its frame is
     * taken off the stack and pushed back whole rather than written
     * into by offset — so every frame carries all three members, and
     * popping one is itself what says the stack was not already empty.
     */
    private static function hasRepeatedMember(#[\SensitiveParameter] string $json): bool
    {
        $length = strlen($json);
        $position = 0;

        /** @var list<array{isObject: bool, expectsName: bool, names: array<string, true>}> $stack */
        $stack = [];

        while ($position < $length) {
            $character = $json[$position];

            if ($character === '{' || $character === '[') {
                $stack[] = ['isObject' => $character === '{', 'expectsName' => true, 'names' => []];
                ++$position;

                continue;
            }

            if ($character === '}' || $character === ']') {
                if ($stack === []) {
                    return true;
                }

                array_pop($stack);
                ++$position;

                continue;
            }

            if ($character === ',') {
                $frame = array_pop($stack);

                if ($frame === null) {
                    return true;
                }

                $frame['expectsName'] = true;
                $stack[] = $frame;
                ++$position;

                continue;
            }

            if ($character !== '"') {
                // Numbers, literals, ':' and whitespace carry no member
                // name and cannot open a string, so a byte at a time is
                // enough to step over them.
                ++$position;

                continue;
            }

            $literal = self::readStringLiteral($json, $position);

            if ($literal === null) {
                return true;
            }

            $frame = array_pop($stack);

            if ($frame === null) {
                return true;
            }

            if ($frame['isObject'] && $frame['expectsName']) {
                $name = self::NAME_PREFIX . $literal;

                if (isset($frame['names'][$name])) {
                    return true;
                }

                $frame['expectsName'] = false;
                $frame['names'][$name] = true;
            }

            $stack[] = $frame;
        }

        return $stack !== [];
    }

    /**
     * Reads the string literal opening at $position, advances $position
     * past its closing quote, and returns the decoded value so an
     * escape resolves to the character it names. Null when the literal
     * is unterminated or is not valid JSON on its own.
     */
    private static function readStringLiteral(#[\SensitiveParameter] string $json, int &$position): ?string
    {
        $length = strlen($json);
        $start = $position;
        ++$position;

        while ($position < $length) {
            $character = $json[$position];

            if ($character === '\\') {
                $position += 2;

                continue;
            }

            ++$position;

            if ($character === '"') {
                $decoded = json_decode(substr($json, $start, $position - $start));

                return is_string($decoded) ? $decoded : null;
            }
        }

        return null;
    }
}
