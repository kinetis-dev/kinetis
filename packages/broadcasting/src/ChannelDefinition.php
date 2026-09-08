<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;

/**
 * One registered `#[BroadcastChannel]` method. The pattern is the whole
 * definition: {@see fromPattern()} parses it once into the segment shape
 * {@see overlaps()} compares, the ordered placeholder names an
 * authorizer's parameters are checked against, and the matcher
 * {@see extract()} runs — all derived, never carried in a cache
 * artifact, so a compiled artifact cannot make a pattern authorize
 * anything its own text does not.
 *
 * The grammar: dot-separated segments, each either a non-empty literal
 * or exactly one whole `{name}` placeholder, with every placeholder name
 * distinct within the pattern. An embedded placeholder (`order-{id}`), a
 * stray brace, an empty segment, and a repeated name are all rejected
 * here, on both the live and the hydrated path.
 */
final readonly class ChannelDefinition
{
    /**
     * @param list<?string> $segments the segment's literal text, or null where it is a placeholder
     * @param list<string> $captureNames placeholder names in pattern order
     * @param ?string $regex null when the pattern carries no placeholder and matches by string equality
     */
    private function __construct(
        public string $pattern,
        public string $class,
        public string $method,
        public bool $usesCurrentUser,
        public array $segments,
        public array $captureNames,
        private ?string $regex,
    ) {}

    /**
     * @throws InvalidChannelAuthorizerException when $pattern violates the grammar
     */
    public static function fromPattern(string $pattern, string $class, string $method, bool $usesCurrentUser): self
    {
        $segments = [];
        $captureNames = [];
        $regexParts = [];

        foreach (explode('.', $pattern) as $segment) {
            if (preg_match('/^\{([A-Za-z_]\w*)\}$/', $segment, $matches) === 1) {
                $name = $matches[1];

                if (in_array($name, $captureNames, true)) {
                    throw InvalidChannelAuthorizerException::duplicatePlaceholderName($pattern, $name);
                }

                $segments[] = null;
                $captureNames[] = $name;
                $regexParts[] = '(?P<' . $name . '>[^.]+)';

                continue;
            }

            if ($segment === '' || str_contains($segment, '{') || str_contains($segment, '}')) {
                throw InvalidChannelAuthorizerException::malformedSegment($pattern, $segment);
            }

            $segments[] = $segment;
            $regexParts[] = preg_quote($segment, '#');
        }

        return new self(
            $pattern,
            $class,
            $method,
            $usesCurrentUser,
            $segments,
            $captureNames,
            $captureNames === [] ? null : '#^' . implode('\.', $regexParts) . '$#',
        );
    }

    /**
     * The placeholder values $channelName supplies, or null when it does
     * not match. An empty array is a match with nothing to capture, so
     * callers must compare against null rather than test emptiness.
     *
     * @return ?array<string, string>
     */
    public function extract(string $channelName): ?array
    {
        if ($this->regex === null) {
            return $channelName === $this->pattern ? [] : null;
        }

        if (preg_match($this->regex, $channelName, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($this->captureNames as $name) {
            $params[$name] = $matches[$name];
        }

        return $params;
    }

    /**
     * Whether some channel name matches both patterns: same segment
     * count, and at every position either both segments are the same
     * literal or at least one is a placeholder. A placeholder never
     * consumes a dot, so a different segment count can never overlap,
     * and one position holding two unequal literals is enough to make
     * the two disjoint.
     */
    public function overlaps(self $other): bool
    {
        if (count($this->segments) !== count($other->segments)) {
            return false;
        }

        foreach ($this->segments as $index => $segment) {
            $otherSegment = $other->segments[$index];

            if ($segment !== null && $otherSegment !== null && $segment !== $otherSegment) {
                return false;
            }
        }

        return true;
    }
}
