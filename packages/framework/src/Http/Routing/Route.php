<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Http\Routing\Exception\InvalidRouteDefinitionException;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;

/**
 * A single registered route: which controller method handles it, and the
 * compiled regex used to test an incoming path and extract its {placeholders}.
 *
 * A path template describes URL structure and nothing else. A `{name}`
 * placeholder occupies one URL segment and matches any non-`/` run; what
 * a captured value may actually hold is described where the value is
 * consumed, by the controller parameter's own type and validation
 * attributes. There is no inline constraint syntax, so a `{...}`
 * expression that isn't a plain placeholder name — or one written
 * directly against another placeholder, where no boundary separates the
 * two — is a mistake in the template and is rejected at registration
 * rather than compiled into something that quietly matches the wrong
 * requests.
 *
 * @phpstan-type PathSegment array{type: 'literal', value: string}|array{type: 'placeholder', name: string}
 */
final class Route
{
    /**
     * The one, fixed PCRE delimiter every compiled route pattern uses.
     * "~" has no PCRE-syntactic role anywhere — not in any "(?...)"
     * construct, not as a quantifier, not inside a character class — so
     * a literal "~" in a template is escaped as ordinary literal text
     * like any other character.
     */
    private const string DELIMITER = '~';

    private readonly string $pattern;

    /** @var list<string> */
    private readonly array $paramNames;

    /** Always canonical: a leading slash, no trailing one. See normalizePath(). */
    public readonly string $pathTemplate;

    /**
     * A normalized HTTP method token: one or more of RFC 9110's own
     * `tchar` set (`!#$%&'*+-.^_`|~`, a digit, or a letter), restricted
     * to uppercase letters only — the deliberate normalization rule this
     * class enforces, kept even though the real token grammar itself is
     * case-sensitive and permits lowercase. `A-Z` alone would reject
     * every real extension/WebDAV method carrying a digit or token
     * punctuation — `M-SEARCH`, `VERSION-CONTROL` — which are genuinely
     * valid HTTP method tokens, not malformed input.
     */
    private const string HTTP_METHOD_PATTERN = '/^[A-Z0-9!#$%&\'*+\-.^_`|~]+$/D';

    /** A backslash-separated sequence of identifiers — a valid class-string shape. */
    private const string CLASS_STRING_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    /** A plain PHP identifier — a valid method-name shape. */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D';

    /**
     * A placeholder name: a PHP identifier restricted to ASCII, which is
     * exactly what PCRE accepts as a named capture group. A name outside
     * this grammar could not compile into a working matcher, so it is
     * rejected while the template is being parsed rather than left to
     * fail as an unnamed group at match time.
     */
    private const string PLACEHOLDER_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** A `@name` middleware group reference — see Kinetis\Http\Attributes\Middleware::GROUP_PREFIX. */
    private const string GROUP_REFERENCE_PATTERN = '/^@[A-Za-z0-9_.-]+$/D';

    public function __construct(
        public readonly string $httpMethod,
        string $pathTemplate,
        /** @var class-string */
        public readonly string $controllerClass,
        public readonly string $controllerMethod,
        public readonly int $status,
        /** @var list<string> each entry is either a middleware class-string or a `@name` group reference — see Kinetis\Http\Attributes\Middleware */
        public readonly array $middleware = [],
    ) {
        self::assertValidDefinition($httpMethod, $pathTemplate, $controllerClass, $controllerMethod, $status, $middleware);

        $this->pathTemplate = self::normalizePath($pathTemplate);
        $segments = self::parse($this->pathTemplate);
        $this->paramNames = self::collectParams($segments, $this->pathTemplate);
        $this->pattern = self::compile($segments);
    }

    /**
     * Every real `RouteAttribute` implementation already returns a
     * normalized method token and a real int status, so none of this is
     * reachable from a genuine attribute in source code — it exists for
     * data replayed from a compiled cache artifact, which carries no such
     * guarantee, and this constructor is the one place both the live
     * (`Router::register()`) and cached (`Router::fromArray()`) paths
     * funnel through. `$pathTemplate` is checked for control characters
     * (including a NUL byte) here, before normalization — left
     * unrejected, either would compile into a real PCRE pattern matching
     * on unexpected byte sequences rather than failing loudly.
     *
     * @param list<string> $middleware
     */
    private static function assertValidDefinition(
        string $httpMethod,
        string $pathTemplate,
        string $controllerClass,
        string $controllerMethod,
        int $status,
        array $middleware,
    ): void {
        if (preg_match(self::HTTP_METHOD_PATTERN, $httpMethod) !== 1) {
            throw InvalidRouteDefinitionException::invalidHttpMethod($httpMethod);
        }

        if ($status < 100 || $status > 599) {
            throw InvalidRouteDefinitionException::statusOutOfRange($status);
        }

        if (preg_match(self::CLASS_STRING_PATTERN, $controllerClass) !== 1) {
            throw InvalidRouteDefinitionException::invalidControllerClass($controllerClass);
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $controllerMethod) !== 1) {
            throw InvalidRouteDefinitionException::invalidControllerMethod($controllerMethod);
        }

        foreach ($middleware as $reference) {
            $isGroupReference = str_starts_with($reference, '@');

            if ($isGroupReference ? preg_match(self::GROUP_REFERENCE_PATTERN, $reference) !== 1
                : preg_match(self::CLASS_STRING_PATTERN, $reference) !== 1) {
                throw InvalidRouteDefinitionException::invalidMiddlewareReference($reference);
            }
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $pathTemplate) === 1) {
            throw InvalidRoutePathException::forControlCharacters($pathTemplate);
        }
    }

    /**
     * Walks the parsed segments once, collecting each placeholder's name
     * and rejecting a repeat — two identically-named capture groups can't
     * compile into one working regex.
     *
     * @param list<PathSegment> $segments
     * @return list<string>
     */
    private static function collectParams(array $segments, string $pathTemplate): array
    {
        $paramNames = [];

        foreach ($segments as $segment) {
            if ($segment['type'] !== 'placeholder') {
                continue;
            }

            if (in_array($segment['name'], $paramNames, true)) {
                throw InvalidRoutePathException::duplicatePlaceholderName($pathTemplate, $segment['name']);
            }

            $paramNames[] = $segment['name'];
        }

        return $paramNames;
    }

    /**
     * Identifies the set of request paths this route claims: the HTTP
     * method plus the template with placeholder *names* normalized away.
     * Two routes with the same key match exactly the same requests —
     * `GET /users/{id}` and `GET /users/{userId}` collide — which is what
     * Router::register() checks to reject a silent first-match-wins
     * conflict at registration time.
     */
    public function conflictKey(): string
    {
        $shape = '';

        foreach (self::parse($this->pathTemplate) as $segment) {
            $shape .= $segment['type'] === 'placeholder' ? '{}' : $segment['value'];
        }

        return $this->httpMethod . ' ' . $shape;
    }

    /**
     * A stable, content-only ordering for `Router::match()`'s first-
     * match-wins scan — never dependent on registration or reflection/
     * scan order, so live discovery and a compiled-cache round trip
     * always produce the identical match order for the identical set of
     * routes.
     *
     * Compares real, `/`-delimited URL path segments position by
     * position, most-specific-wins at the first point of difference. Each
     * segment is ranked into one of three tiers, from most to least
     * specific: fully literal (`self`, `report-2026.pdf`); mixed literal
     * and placeholder content within the one segment (`report-{id}.pdf`);
     * a placeholder occupying the whole segment (`{id}`). If every shared
     * segment ties, the route with *more* segments is treated as more
     * specific (a deeper, more concrete path). A route that still ties on
     * every segment falls back to a fully content-based tiebreak —
     * httpMethod, then pathTemplate, then controllerClass/
     * controllerMethod — so two routes can never compare equal unless
     * they're the exact same route.
     */
    public static function compareForMatching(self $a, self $b): int
    {
        $segmentsA = self::urlSegmentGroups($a->pathTemplate);
        $segmentsB = self::urlSegmentGroups($b->pathTemplate);
        $shared = min(count($segmentsA), count($segmentsB));

        for ($i = 0; $i < $shared; $i++) {
            $bySpecificity = self::urlSegmentSpecificity($segmentsB[$i]) <=> self::urlSegmentSpecificity($segmentsA[$i]);

            if ($bySpecificity !== 0) {
                return $bySpecificity;
            }
        }

        return (count($segmentsB) <=> count($segmentsA))
            ?: ($a->httpMethod <=> $b->httpMethod)
            ?: ($a->pathTemplate <=> $b->pathTemplate)
            ?: ($a->controllerClass <=> $b->controllerClass)
            ?: ($a->controllerMethod <=> $b->controllerMethod);
    }

    /**
     * Regroups {@see parse()}'s flat literal/placeholder token list back
     * into real, `/`-delimited URL segments — each returned group is the
     * ordered list of tokens making up exactly one segment, e.g.
     * `report-{id}.pdf` groups a literal token, a placeholder token, and
     * another literal token together as one segment. A leading `/` (every
     * normalized template has exactly one) always produces an empty
     * leading group as a side effect of splitting on it; discarded unless
     * it's the *only* group produced, which is what the root path (`/`)
     * reduces to — one segment, an empty one, kept so the root path still
     * orders consistently against every other route rather than comparing
     * as zero shared segments against everything.
     *
     * @return list<list<PathSegment>>
     */
    private static function urlSegmentGroups(string $pathTemplate): array
    {
        /** @var list<list<PathSegment>> $groups */
        $groups = [[]];
        $currentGroup = 0;

        foreach (self::parse($pathTemplate) as $token) {
            if ($token['type'] === 'placeholder') {
                $groups[$currentGroup][] = $token;

                continue;
            }

            foreach (explode('/', $token['value']) as $index => $part) {
                if ($index > 0) {
                    $groups[] = [];
                    $currentGroup++;
                }

                if ($part !== '') {
                    /** @var PathSegment $literalPart */
                    $literalPart = ['type' => 'literal', 'value' => $part];
                    $groups[$currentGroup][] = $literalPart;
                }
            }
        }

        return count($groups) > 1 ? array_slice($groups, 1) : $groups;
    }

    /**
     * How specific one real URL segment is, as the group of tokens
     * {@see urlSegmentGroups()} found for it. Three tiers, most to least
     * specific: fully literal (no placeholder token at all), mixed (at
     * least one literal token alongside at least one placeholder token),
     * and a pure placeholder (no literal token at all).
     *
     * @param list<PathSegment> $group
     */
    private static function urlSegmentSpecificity(array $group): int
    {
        $hasLiteral = false;
        $hasPlaceholder = false;

        foreach ($group as $token) {
            if ($token['type'] === 'literal') {
                $hasLiteral = true;

                continue;
            }

            $hasPlaceholder = true;
        }

        if (!$hasPlaceholder) {
            // Fully literal, including the empty root "segment".
            return 3;
        }

        return $hasLiteral ? 2 : 1;
    }

    /**
     * @return array<string,string>|null
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, self::normalizePath($path), $matches) !== 1) {
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
     * The one canonical form for a compiled path: a leading slash, no
     * trailing one, `/` itself unchanged. Applied here rather than in
     * Router so it holds for every Route however it was built — including
     * fromArray(), and including a path assembled from a #[RoutePrefix].
     *
     * Applied to the request path too, in matchPath() — so `/users/` and
     * `/users` are one route answering one set of requests, rather than
     * one route and a 404. Public so anything comparing a request path
     * against a registered one applies this same rule rather than
     * reimplementing it.
     */
    public static function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /**
     * Splits $pathTemplate into a flat list of literal runs and `{name}`
     * placeholders. A `{` always opens a placeholder, so an unterminated
     * brace expression, or one holding anything other than a plain
     * placeholder name, is a malformed template rather than literal text.
     * Two placeholders may not sit directly against each other either:
     * both match greedily up to the next `/`, so nothing decides where
     * the first ends.
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

            $close = strpos($pathTemplate, '}', $i);

            if ($close === false) {
                throw InvalidRoutePathException::malformedPlaceholder($pathTemplate, substr($pathTemplate, $i));
            }

            $name = substr($pathTemplate, $i + 1, $close - $i - 1);

            if (preg_match(self::PLACEHOLDER_NAME_PATTERN, $name) !== 1) {
                throw InvalidRoutePathException::malformedPlaceholder(
                    $pathTemplate,
                    substr($pathTemplate, $i, $close - $i + 1),
                );
            }

            $previous = $segments === [] ? null : $segments[array_key_last($segments)];

            if ($previous !== null && $previous['type'] === 'placeholder') {
                throw InvalidRoutePathException::adjacentPlaceholders($pathTemplate, $previous['name'], $name);
            }

            /** @var PathSegment $placeholderSegment */
            $placeholderSegment = ['type' => 'placeholder', 'name' => $name];
            $segments[] = $placeholderSegment;

            $i = $close + 1;
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
     * @param list<PathSegment> $segments
     */
    private static function compile(array $segments): string
    {
        $regex = '';

        foreach ($segments as $segment) {
            $regex .= $segment['type'] === 'placeholder'
                ? '(?P<' . $segment['name'] . '>[^/]+)'
                : preg_quote($segment['value'], self::DELIMITER);
        }

        return self::DELIMITER . '^' . $regex . '$' . self::DELIMITER;
    }
}
