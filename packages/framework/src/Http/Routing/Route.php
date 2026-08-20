<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Http\Routing\Exception\InvalidRouteConstraintException;

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
 * That scanner reads enough PCRE to know where a `}` is *not* the
 * placeholder's own — an escape (`\}`), a character class (`[{]`,
 * including its POSIX/collating/equivalence sub-forms), a `\Q...\E`
 * quoted span, and a `(?#...)` comment group — but it is a bounded
 * reader of those constructs, not a full PCRE parser, and the supported
 * constraint grammar is exactly what it can read faithfully. Two things
 * it rules out instead of guessing at, both rejected by name at
 * registration by {@see unsupportedConstruct()} rather than silently
 * mis-scanned: extended mode (the `x` flag, whose `#`-to-end-of-line
 * comments hide a `}` behind flag *scope*) and `(*...)` control verbs
 * (whose own shape varies by verb). Everything the grammar does accept
 * is embedded faithfully, including a literal delimiter inside a quoted
 * span, which needs a rewrite rather than a plain escape — see
 * {@see escapeDelimiter()}.
 *
 * @phpstan-type PathSegment array{type: 'literal', value: string}|array{type: 'placeholder', name: string, pattern: ?string}
 */
final class Route
{
    /**
     * The one, fixed PCRE delimiter every compiled route pattern uses.
     * Never varies per route — a literal, unescaped occurrence inside a
     * constraint pattern is handled by escaping it (see
     * escapeDelimiter()), not by picking a different delimiter to dodge
     * it, so there's no reason for this to be anything other than a
     * single shared constant.
     *
     * "#" (this constant's own first choice) was reverted once a real
     * counterexample surfaced: "#" is PCRE syntax, not just a candidate
     * delimiter, inside a "(?#comment)" comment group — escapeDelimiter()
     * blindly escaping an unescaped "#" corrupts "(?#note)" into
     * "(?\#note)", which no longer opens a comment group at all, breaking
     * a perfectly valid caller fragment rather than merely dodging a
     * collision. "~" has no PCRE-syntactic role anywhere — not in any
     * "(?...)" construct, not as a quantifier, not inside a character
     * class — confirmed directly against PCRE's own metacharacter list
     * before picking it, not assumed safe by elimination. Escaping a
     * literal, unescaped "~" the caller's own pattern happens to contain
     * is therefore always exactly that: hiding a literal delimiter
     * occurrence from PHP's own end-of-pattern search, never a change to
     * what the fragment means to PCRE itself.
     */
    private const string DELIMITER = '~';

    private readonly string $pattern;

    /** @var list<string> */
    private readonly array $paramNames;

    /** @var array<string, string> constraint pattern by placeholder name, only for placeholders that declared one */
    private readonly array $paramPatterns;

    /** Always canonical: a leading slash, no trailing one. See normalizePath(). */
    public readonly string $pathTemplate;

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
        $this->pathTemplate = self::normalizePath($pathTemplate);
        $segments = self::parse($this->pathTemplate);
        $paramNames = [];
        $paramPatterns = [];

        foreach ($segments as $segment) {
            if ($segment['type'] === 'placeholder') {
                if (in_array($segment['name'], $paramNames, true)) {
                    throw InvalidRouteConstraintException::duplicatePlaceholderName($this->pathTemplate, $segment['name']);
                }

                $paramNames[] = $segment['name'];

                if ($segment['pattern'] !== null) {
                    $unsupported = self::unsupportedConstruct($segment['pattern']);

                    if ($unsupported !== null) {
                        throw $unsupported === 'extendedMode'
                            ? InvalidRouteConstraintException::extendedModeNotSupported(
                                $this->pathTemplate,
                                $segment['name'],
                                $segment['pattern'],
                            )
                            : InvalidRouteConstraintException::controlVerbNotSupported(
                                $this->pathTemplate,
                                $segment['name'],
                                $segment['pattern'],
                            );
                    }

                    $paramPatterns[$segment['name']] = $segment['pattern'];
                }
            }
        }

        $this->paramNames = $paramNames;
        $this->paramPatterns = $paramPatterns;
        $this->pattern = self::compile($segments);

        // preg_match() returns false (with an E_WARNING, not an
        // exception) rather than throwing on a compile error — left
        // unchecked, a malformed {name:pattern} constraint would only
        // surface as a silent, permanent 404 on the route's first real
        // request, not at registration where the actual mistake is.
        if (@preg_match($this->pattern, '') === false) {
            foreach ($paramPatterns as $name => $pattern) {
                // Re-check each constraint individually to name the
                // actual offending one, rather than the whole compiled
                // route pattern — escaped exactly as compile() itself
                // escapes it (see escapeDelimiter()), so this check
                // agrees with what actually gets compiled instead of
                // misreporting a pattern compile() handles correctly.
                $escaped = self::escapeDelimiter($pattern);

                if (@preg_match(self::DELIMITER . $escaped . self::DELIMITER, '') === false) {
                    throw InvalidRouteConstraintException::malformedPattern($this->pathTemplate, $name, $pattern);
                }
            }

            // A compile failure not isolated to one constraint's own
            // pattern in isolation — still a real failure, named against
            // the whole route rather than a specific placeholder.
            throw InvalidRouteConstraintException::malformedRoute($this->pathTemplate);
        }
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
     * Identifies the set of request paths this route claims: the HTTP
     * method plus the template with placeholder *names* normalized away
     * (constraint patterns kept, since they change what matches). Two
     * routes with the same key match exactly the same requests —
     * `GET /users/{id}` and `GET /users/{userId}` collide, while
     * `GET /users/{id:\d+}` and `GET /users/{id}` don't — which is what
     * Router::register() checks to reject a silent first-match-wins
     * conflict at registration time.
     */
    public function conflictKey(): string
    {
        $shape = '';

        foreach (self::parse($this->pathTemplate) as $segment) {
            $shape .= $segment['type'] === 'placeholder'
                ? '{' . ($segment['pattern'] ?? '') . '}'
                : $segment['value'];
        }

        return $this->httpMethod . ' ' . $shape;
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
     * one route and a 404. Public because Kernel compares its own `/mcp`
     * endpoint the same way, and there should be one rule.
     */
    public static function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
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
     *
     * A backslash-escaped brace (`\{`/`\}`, a real PCRE construct — an
     * escaped literal brace character, e.g. the `\}` in `{value:\}}`
     * matching a literal `}`) is skipped as a pair rather than counted
     * toward depth: it's the constraint pattern's own literal text, not
     * this scanner's nesting syntax, and counting it would close the
     * placeholder one character early. Consuming exactly two bytes per
     * backslash also gets a run of several backslashes right for free —
     * an even run pairs off completely, leaving the following character
     * genuinely unescaped; an odd run leaves exactly one backslash
     * escaping whatever comes next — the same rule real regex engines
     * use, not something special-cased here.
     *
     * A `{`/`}` is only this scanner's own syntax where it sits in
     * ordinary regex text. Everywhere else it is a literal the pattern
     * happens to contain, and {@see skipNonSyntax()} is the single
     * definition of "everywhere else" — an escape pair, a `\Q...\E`
     * quoted span, a character class, or a `(?#...)` comment group, each
     * skipped as one unit rather than scanned character by character.
     *
     * That definition is shared with {@see unsupportedConstruct()} rather
     * than restated, which is the point: the two once disagreed about
     * which contexts existed, and a construct one of them knew to skip
     * was read as live syntax by the other.
     */
    private static function findMatchingBrace(string $pathTemplate, int $openBraceIndex): int
    {
        $length = strlen($pathTemplate);
        $depth = 1;
        $j = $openBraceIndex + 1;

        while ($j < $length && $depth > 0) {
            $skipped = self::skipNonSyntax($pathTemplate, $j, $length);

            if ($skipped !== null) {
                $j = $skipped;

                continue;
            }

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
     * The index just past the construct starting at $i when that
     * construct is one PCRE reads as literal or discarded text, or null
     * when $i is ordinary regex syntax the caller should interpret
     * itself.
     *
     * The one place this class decides what "ordinary regex syntax"
     * means, so that finding a placeholder's closing brace and deciding
     * whether a pattern uses an unsupported construct cannot disagree
     * about it:
     *
     * - an escape pair (`\}`), consumed two bytes at a time, which also
     *   handles a run of backslashes correctly for free — an even run
     *   pairs off completely and leaves the next character unescaped, an
     *   odd one leaves exactly one backslash escaping it;
     * - a `\Q...\E` quoted span, where nothing at all is syntax
     *   (`\Q{\E` matches a literal `{`);
     * - a character class, whose contents are ordinary members however
     *   they are spelled (`[{]`, `[(*]`);
     * - a `(?#...)` comment group, which PCRE discards outright and which
     *   ends at its very first `)` with no nesting and no escaping
     *   recognized inside — confirmed against real preg_match() rather
     *   than read off the syntax alone.
     */
    private static function skipNonSyntax(string $pattern, int $i, int $length): ?int
    {
        if ($pattern[$i] === '\\' && $i + 1 < $length) {
            if ($pattern[$i + 1] === 'Q') {
                $end = strpos($pattern, '\E', $i + 2);

                return $end === false ? $length : $end + 2;
            }

            return $i + 2;
        }

        if ($pattern[$i] === '[') {
            return self::skipCharacterClass($pattern, $i, $length);
        }

        if ($pattern[$i] === '(' && $i + 2 < $length && $pattern[$i + 1] === '?' && $pattern[$i + 2] === '#') {
            $close = strpos($pattern, ')', $i + 3);

            return $close === false ? $length : $close + 1;
        }

        return null;
    }

    /**
     * The index just past the character class opening at $i, whose
     * every member is an ordinary character however it is spelled.
     *
     * Four PCRE rules decide where it ends, each confirmed against real
     * preg_match() rather than read off the syntax:
     *
     * - a `]` in the first member position is a literal member rather
     *   than the close (`[]a]` matches `]` or `a`), as is one straight
     *   after a negating `^`;
     * - an escaped `\]` is a member too;
     * - `\Q...\E` is processed inside a class, so `[\Q]\E]` is a class
     *   holding a literal `]` — the span has to be skipped here as well
     *   as outside, or its contents end the class early;
     * - a POSIX/collating/equivalence sub-form (`[:alpha:]`, `[.ch.]`,
     *   `[=e=]`) carries its own `:]`/`.]`/`=]` closer, which is not the
     *   enclosing class's.
     */
    private static function skipCharacterClass(string $pattern, int $i, int $length): int
    {
        $j = $i + 1;

        if ($j < $length && $pattern[$j] === '^') {
            $j++;
        }

        if ($j < $length && $pattern[$j] === ']') {
            $j++;
        }

        while ($j < $length) {
            if ($pattern[$j] === '\\' && $j + 1 < $length) {
                if ($pattern[$j + 1] === 'Q') {
                    $end = strpos($pattern, '\E', $j + 2);
                    $j = $end === false ? $length : $end + 2;

                    continue;
                }

                $j += 2;

                continue;
            }

            if ($pattern[$j] === '[' && $j + 1 < $length && in_array($pattern[$j + 1], [':', '.', '='], true)) {
                $marker = $pattern[$j + 1] . ']';
                $close = strpos($pattern, $marker, $j + 2);
                $j = $close === false ? $length : $close + 2;

                continue;
            }

            if ($pattern[$j] === ']') {
                return $j + 1;
            }

            $j++;
        }

        return $length;
    }

    /**
     * The unsupported construct $pattern uses, or null when it uses
     * none — the two things {@see findMatchingBrace()} refuses rather
     * than lexes, both because a `}` inside them would stop being the
     * placeholder's own close and neither can be skipped by a rule as
     * simple as the constructs that scanner does handle.
     *
     * `'extendedMode'`: an inline flag group that *enables* `x`, which
     * makes an unescaped `#` start a comment running to the end of the
     * line. Whether it is on at a given byte is flag scope — `(?x)` runs
     * to the end of its enclosing group, `(?x:...)` only to that group's
     * — which is parser state rather than a construct with a fixed
     * opener and closer.
     *
     * `'controlVerb'`: a `(*...)` verb. Some carry a free-text argument
     * that can contain a brace and end at the first `)` (`(*MARK:})`),
     * while others are group forms holding a whole sub-pattern with
     * nested parentheses (`(*atomic:a(b))`) — one spelling, two
     * incompatible shapes, confirmed against real preg_match() on both
     * supported PHP versions. Telling them apart means tracking PCRE's
     * verb list, which is the treadmill this bounded scanner exists to
     * step off; `(?>...)` covers the useful case without one.
     *
     * The `x` test respects the set/unset split a flag run carries: in
     * `(?im-sx:...)` the letters after `-` are being turned *off*, so
     * that fragment and `(?-x:...)` enable nothing and register normally
     * — verified against real preg_match() rather than read off the
     * syntax, since treating either as an enable is exactly the
     * inversion this once shipped with.
     *
     * Only text that is actually syntax is inspected: this walks the
     * pattern through the same {@see skipNonSyntax()} the brace scanner
     * uses, so a `(*` or `(?x)` that is ordinary literal text — members
     * of a character class (`[(*]`), the contents of a `\Q...\E` span,
     * or the body of a `(?#...)` comment — is not mistaken for the
     * construct it merely spells. Reporting one of those as an active
     * control verb was both a rejection of a valid route and a factually
     * untrue error, and it broke the composition the grammar promises:
     * a supported character class does not stop being supported because
     * of which ordinary members it holds.
     *
     * @return 'extendedMode'|'controlVerb'|null
     */
    private static function unsupportedConstruct(string $pattern): ?string
    {
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            $skipped = self::skipNonSyntax($pattern, $i, $length);

            if ($skipped !== null) {
                $i = $skipped;

                continue;
            }

            if ($pattern[$i] === '(' && $i + 1 < $length) {
                if ($pattern[$i + 1] === '*') {
                    return 'controlVerb';
                }

                if ($pattern[$i + 1] === '?' && self::enablesExtendedMode($pattern, $i + 2, $length)) {
                    return 'extendedMode';
                }
            }

            $i++;
        }

        return null;
    }

    /**
     * Whether the inline flag group starting at $start (just past its own
     * `(?`) turns `x` on. Everything before the run's `-` is being set
     * and everything after it unset, so only the first half is asked
     * about; `^` resets the inherited options and then sets whatever
     * follows, which leaves those letters on the setting side too.
     *
     * Returns false for anything that isn't a flag group at all — a
     * named group, a lookaround, a comment — since a run of flag letters
     * is only a flag group when `)` or `:` closes it.
     */
    private static function enablesExtendedMode(string $pattern, int $start, int $length): bool
    {
        $enabled = '';
        $unsetting = false;
        $j = $start;

        while ($j < $length && (ctype_alpha($pattern[$j]) || $pattern[$j] === '-' || $pattern[$j] === '^')) {
            if ($pattern[$j] === '-') {
                $unsetting = true;
            } elseif (!$unsetting && $pattern[$j] !== '^') {
                $enabled .= $pattern[$j];
            }

            $j++;
        }

        return $j < $length
            && ($pattern[$j] === ')' || $pattern[$j] === ':')
            && str_contains($enabled, 'x');
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
                ? '(?P<' . $segment['name'] . '>' . ($segment['pattern'] !== null ? self::escapeDelimiter($segment['pattern']) : '[^/]+') . ')'
                : preg_quote($segment['value'], self::DELIMITER);
        }

        return self::DELIMITER . '^' . $regex . '$' . self::DELIMITER;
    }

    /**
     * A constraint pattern is regex text this class inserts rather than
     * rewrites, so a literal, *unescaped* occurrence of this class's own
     * PCRE delimiter inside one — a character class like `[A-F~]`, say —
     * would otherwise terminate the delimiter early and corrupt the whole
     * compiled pattern, rejecting a perfectly valid PCRE fragment as
     * "malformed" for a reason that has nothing to do with the fragment
     * itself. Escaping every unescaped occurrence with a backslash is the
     * standard PCRE way to include a literal delimiter character inside a
     * delimited pattern. An *already* escaped occurrence (a caller who
     * wrote `\~` themselves) is left exactly as it was — re-escaping it
     * would turn a correct `\~` into `\\~`, a literal backslash followed
     * by a now-*unescaped* delimiter, corrupting the one case this exists
     * to leave alone. Literal path segments need no equivalent treatment:
     * preg_quote() is given the same fixed delimiter to escape, which it
     * already does correctly for any input.
     *
     * Inside a `\Q...\E` quoted span a backslash is ordinary literal
     * text, not an escape — so a plain `\~` there would match a
     * backslash *and* a tilde rather than the single tilde the caller
     * wrote, silently changing what the route matches. The span is closed
     * and reopened around the escape instead (`\E\~\Q`), which PHP's own
     * delimiter scan skips as an escaped character while PCRE reads it as
     * exactly the one literal delimiter it replaces — confirmed against
     * real preg_match() calls, both that the naive form is wrong and that
     * this form is identical to the same fragment under a delimiter that
     * needs no escaping at all.
     */
    private static function escapeDelimiter(string $pattern): string
    {
        $escaped = '';
        $length = strlen($pattern);
        $i = 0;
        $inQuotedSpan = false;

        while ($i < $length) {
            // Tracked before the escape-pair skip below, since inside a
            // quoted span there are no escape pairs to skip — only `\E`
            // is recognized there at all.
            if ($pattern[$i] === '\\' && $i + 1 < $length && ($pattern[$i + 1] === 'Q' || $pattern[$i + 1] === 'E')) {
                $inQuotedSpan = $pattern[$i + 1] === 'Q';
                $escaped .= '\\' . $pattern[$i + 1];
                $i += 2;

                continue;
            }

            if (!$inQuotedSpan && $pattern[$i] === '\\' && $i + 1 < $length) {
                $escaped .= $pattern[$i] . $pattern[$i + 1];
                $i += 2;

                continue;
            }

            if ($pattern[$i] === self::DELIMITER) {
                $escaped .= $inQuotedSpan
                    ? '\\E\\' . self::DELIMITER . '\\Q'
                    : '\\' . self::DELIMITER;
                $i++;

                continue;
            }

            $escaped .= $pattern[$i];
            $i++;
        }

        return $escaped;
    }
}
