<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

/**
 * A single registered route: which controller method handles it, and the
 * compiled regex used to test an incoming path and extract its {placeholders}.
 *
 * A placeholder may carry an optional `{name:pattern}` regex constraint —
 * `pattern` is a raw regex fragment (no delimiters, no anchors), inserted
 * directly into the named capture group, e.g. `{id:\d+}` or
 * `{id:[0-9a-f-]{8}}` for a fixed-length hex segment. Omitted entirely, a
 * placeholder still matches any non-`/` run exactly as before — this is
 * additive syntax, not a change to existing route templates.
 *
 * Parsed with a manual, brace-depth-aware scanner rather than one regex —
 * a constraint pattern legitimately containing its own `{`/`}` (a `{n}`
 * repetition quantifier, e.g. `[0-9a-f]{8}` above) breaks a naive
 * `[^}]+`-delimited capture the moment the pattern's own closing `}`
 * looks like the placeholder's own; a depth counter is the only way to
 * find the *placeholder's* matching brace correctly regardless of what
 * the pattern inside contains.
 *
 * @phpstan-type PathSegment array{type: 'literal', value: string}|array{type: 'placeholder', name: string, pattern: ?string}
 */
final class Route
{
    private readonly string $pattern;

    /** @var list<string> */
    private readonly array $paramNames;

    /** @var array<string, string> constraint pattern by placeholder name, only for placeholders that declared one */
    private readonly array $paramPatterns;

    public function __construct(
        public readonly string $httpMethod,
        public readonly string $pathTemplate,
        public readonly string $controllerClass,
        public readonly string $controllerMethod,
        public readonly int $status,
        /** @var list<class-string> */
        public readonly array $middleware = [],
    ) {
        $segments = self::parse($pathTemplate);
        $paramNames = [];
        $paramPatterns = [];

        foreach ($segments as $segment) {
            if ($segment['type'] === 'placeholder') {
                $paramNames[] = $segment['name'];

                if ($segment['pattern'] !== null) {
                    $paramPatterns[$segment['name']] = $segment['pattern'];
                }
            }
        }

        $this->paramNames = $paramNames;
        $this->paramPatterns = $paramPatterns;
        $this->pattern = self::compile($segments);
    }

    /**
     * The raw regex fragment a `{name:pattern}` placeholder declared, or
     * null for a plain `{name}` placeholder (or a non-path-parameter
     * name) — read by OpenApiGenerator to describe the constraint in a
     * path parameter's schema.
     */
    public function pathParameterPattern(string $name): ?string
    {
        return $this->paramPatterns[$name] ?? null;
    }

    /**
     * The path template with every `{name:pattern}` constraint stripped
     * back to plain `{name}` — OpenAPI's own path-templating syntax has
     * no concept of an inline regex constraint (a constraint belongs in
     * the parameter's own `schema.pattern` instead, see
     * OpenApiGenerator::describeOperation()), so the raw $pathTemplate
     * this class matches against internally is never itself valid as an
     * OpenAPI `paths` key once a placeholder declares one.
     */
    public function openApiPathTemplate(): string
    {
        $template = '';

        foreach (self::parse($this->pathTemplate) as $segment) {
            $template .= $segment['type'] === 'placeholder' ? '{' . $segment['name'] . '}' : $segment['value'];
        }

        return $template;
    }

    /**
     * @return array<string,string>|null
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($this->paramNames as $name) {
            $params[$name] = $matches[$name];
        }

        return $params;
    }

    /**
     * @return list<string>
     */
    public function pathParameterNames(): array
    {
        return $this->paramNames;
    }

    /**
     * Splits $pathTemplate into a flat list of literal runs and
     * `{name}`/`{name:pattern}` placeholders, tracking brace depth so a
     * constraint pattern's own `{`/`}` (a repetition quantifier) never
     * gets mistaken for the placeholder's own closing brace.
     *
     * @return list<PathSegment>
     */
    private static function parse(string $pathTemplate): array
    {
        $segments = [];
        $length = strlen($pathTemplate);
        $literalStart = 0;
        $i = 0;

        while ($i < $length) {
            if ($pathTemplate[$i] !== '{') {
                $i++;

                continue;
            }

            if ($i > $literalStart) {
                /** @var PathSegment $literalSegment */
                $literalSegment = ['type' => 'literal', 'value' => substr($pathTemplate, $literalStart, $i - $literalStart)];
                $segments[] = $literalSegment;
            }

            $j = self::findMatchingBrace($pathTemplate, $i);
            $inner = substr($pathTemplate, $i + 1, $j - $i - 1);
            $placeholderSegment = self::parsePlaceholderSegment($inner);

            if ($placeholderSegment === null) {
                $i++;

                continue;
            }

            $segments[] = $placeholderSegment;

            $i = $j + 1;
            $literalStart = $i;
        }

        if ($literalStart < $length) {
            /** @var PathSegment $trailingLiteralSegment */
            $trailingLiteralSegment = ['type' => 'literal', 'value' => substr($pathTemplate, $literalStart)];
            $segments[] = $trailingLiteralSegment;
        }

        return $segments;
    }

    /**
     * Walks forward from $openBraceIndex (a known `{`) tracking nesting
     * depth to find the index of its own matching `}` — the mechanism
     * that lets a constraint pattern's fixed-length quantifier (`{40}`
     * inside `{hash:[0-9a-f]{40}}`) never get mistaken for the
     * placeholder's own closing brace. Extracted out of parse()'s own
     * loop body once its combined nesting (an inner while alongside an
     * if/elseif) became the dominant share of that method's cognitive
     * complexity — a pure move, no behavior change.
     */
    private static function findMatchingBrace(string $pathTemplate, int $openBraceIndex): int
    {
        $length = strlen($pathTemplate);
        $depth = 1;
        $j = $openBraceIndex + 1;

        while ($j < $length && $depth > 0) {
            if ($pathTemplate[$j] === '{') {
                $depth++;
            } elseif ($pathTemplate[$j] === '}') {
                $depth--;
            }

            if ($depth > 0) {
                $j++;
            }
        }

        return $j;
    }

    /**
     * Validates and builds one `{name}`/`{name:pattern}` placeholder's
     * segment from its already-extracted inner text, or null when the
     * name isn't a plain identifier — most likely someone using literal
     * braces for something else entirely, matching the exact tolerance
     * the previous `\{(\w+)\}` regex already gave that case: the caller
     * falls through to plain-character advancement so it ends up quoted
     * as literal text, rather than this producing an invalid PCRE named
     * group (e.g. one containing a hyphen) that would fail to compile.
     *
     * @return PathSegment|null
     */
    private static function parsePlaceholderSegment(string $inner): ?array
    {
        $colonPos = strpos($inner, ':');
        $name = $colonPos === false ? $inner : substr($inner, 0, $colonPos);

        if (preg_match('/^\w+$/', $name) !== 1) {
            return null;
        }

        /** @var PathSegment */
        return ['type' => 'placeholder', 'name' => $name, 'pattern' => $colonPos === false ? null : substr($inner, $colonPos + 1)];
    }

    /**
     * @param list<PathSegment> $segments
     */
    private static function compile(array $segments): string
    {
        $regex = '';

        foreach ($segments as $segment) {
            $regex .= $segment['type'] === 'placeholder'
                ? '(?P<' . $segment['name'] . '>' . ($segment['pattern'] ?? '[^/]+') . ')'
                : preg_quote($segment['value'], '#');
        }

        return '#^' . $regex . '$#';
    }
}
