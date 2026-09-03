<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Reflection\AttributeScope;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Mirrors `Kinetis\Events\EventListenerRegistry`: `register()` reflects a
 * class for `#[BroadcastChannel]` methods, validating each one's
 * signature at registration time rather than at the first real request —
 * the same fail-fast discipline `EventListenerRegistry::register()`
 * already applies to `#[Listener]`.
 *
 * Implements `CacheableDiscoveryInterface` — declared as this package's
 * `extra.kinetis` `discovery` class, so the framework itself compiles,
 * caches, and binds an instance of this class before `PackageBootstrap`
 * ever runs. `compile()` is the live-discovery path
 * `BroadcastChannelDiscovery::discover()` already provides, reduced to
 * plain data via `toArray()`.
 *
 * A pattern's grammar is deliberately narrow: each dot-separated segment
 * holds **at most one** `{name}` placeholder, optionally surrounded by
 * literal prefix/suffix text, and a placeholder name may appear **at
 * most once** across the whole pattern. Both are enforced in
 * `decomposeSegments()`. This is what makes the precedence between two
 * patterns provably decidable by simple prefix/suffix comparison — see
 * `patternRelation()`'s own docblock — rather than requiring general
 * regular-language containment analysis, which a richer grammar (nested
 * or repeated placeholders within one segment) would need.
 *
 * `match()`'s own correctness depends on `$this->definitions` staying in
 * canonical precedence order at all times — see
 * `assertNoConflictAgainst()`'s own docblock for the precedence rule and
 * why registration/artifact order must never be able to change which
 * definition wins.
 *
 * `register()` is atomic and authoritative for whatever it reflects: a
 * class's live registration replaces **every** definition this registry
 * already attributes to that class with exactly what reflection finds
 * today — nothing committed until the whole batch validates, so a class
 * with one valid method followed by an invalid one leaves the class's
 * prior state (if any) completely untouched, not partially replaced.
 * `$registeredClasses` is set only once a class's own live `register()`
 * call has completed successfully this way; it is **never** set by
 * `fromArray()`. A hydrated cache artifact is untrusted data, not proof
 * that every one of a class's real attributed methods is present, or
 * that none of its rows are stale — a stale, truncated, or crafted
 * artifact must never be able to permanently suppress a class's real
 * methods, nor keep authorizing a channel via a method or pattern the
 * class's live source no longer attributes, once that class is
 * genuinely `register()`ed live. See `register()`'s own docblock for
 * the exact algorithm.
 */
final class BroadcastChannelRegistry implements CacheableDiscoveryInterface
{
    /** @var list<ChannelDefinition> */
    private array $definitions = [];

    /** @var array<class-string, true> */
    private array $registeredClasses = [];

    /**
     * Reflects $class for every `#[BroadcastChannel]` method and
     * atomically replaces every definition this registry already
     * attributes to $class with exactly what that reflection finds —
     * reflection is always authoritative over whatever this registry
     * held for the class before, including a stale, incomplete, or
     * outright fabricated set of rows a prior `fromArray()` hydration
     * may have contributed for it.
     *
     * `$base` is every *other* class's own definitions — computed once,
     * up front, and never touched again — so nothing about registering
     * $class can ever disturb a different class's own state. Every
     * currently attributed method is validated and staged into `$batch`
     * against `$base` plus whatever's already in `$batch` (the latter is
     * what still catches two distinct methods on $class itself claiming
     * the identical pattern); `$this->definitions` is reassigned to
     * `$base` + `$batch` only once every attributed method has validated
     * cleanly. A throw at any point — a malformed signature, a grammar
     * violation, a real conflict against another class's pattern — never
     * touches `$this->definitions` at all, so $class's own pre-existing
     * rows (if any) are left exactly as they were, right alongside every
     * other class's.
     *
     * Because `$base` never includes anything $class itself previously
     * held, a row this registry no longer finds attributed — a bogus
     * artifact entry naming a method that doesn't exist, one for a real
     * method whose `#[BroadcastChannel]` attribute has since been
     * removed, or one carrying a pattern a still-attributed method no
     * longer declares — is simply never carried forward into the
     * replacement: this is what makes a stale authorization policy
     * genuinely removed, not merely shadowed by whatever's freshly
     * reflected alongside it.
     *
     * @param class-string $class
     */
    public function register(string $class): void
    {
        if (isset($this->registeredClasses[$class])) {
            return;
        }

        $reflection = AttributeScope::reflect($class);

        /** @var list<ChannelDefinition> $base */
        $base = array_values(array_filter(
            $this->definitions,
            static fn (ChannelDefinition $definition): bool => $definition->class !== $class,
        ));
        /** @var list<ChannelDefinition> $batch */
        $batch = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(BroadcastChannel::class);

            if ($attributes === []) {
                continue;
            }

            AttributeScope::assertDeclares($method, $class);

            /** @var BroadcastChannel $attribute */
            $attribute = $attributes[0]->newInstance();
            $pattern = $attribute->pattern;

            [$regex, $paramNames] = self::compilePattern($pattern);
            $segments = self::decomposeSegments($pattern);
            $usesCurrentUser = $this->assertSignature($class, $method, $pattern, $paramNames);

            self::assertNoConflictAgainst(
                [...$base, ...$batch],
                $pattern,
                $segments,
                static fn (ChannelDefinition $existing) => throw InvalidChannelAuthorizerException::duplicatePattern(
                    $pattern,
                    $existing->class,
                    $existing->method,
                ),
                static fn (ChannelDefinition $existing) => throw InvalidChannelAuthorizerException::ambiguousPattern(
                    $pattern,
                    $existing->pattern,
                    $existing->class,
                    $existing->method,
                ),
            );

            $batch[] = new ChannelDefinition($pattern, $regex, $paramNames, $class, $method->getName(), $usesCurrentUser);
        }

        // Nothing above this line has touched $this->definitions — a
        // throw at any point leaves it byte-for-byte unchanged,
        // including every row $class itself previously held.
        $this->definitions = [...$base, ...$batch];
        usort($this->definitions, self::compareBySpecificity(...));
        $this->registeredClasses[$class] = true;
    }

    /**
     * $channelName never carries a `private-`/`presence-` prefix — the
     * caller strips it before matching, since the prefix selects which
     * auth response to build, not which pattern applies.
     */
    public function match(string $channelName): ?ChannelMatch
    {
        foreach ($this->definitions as $definition) {
            if (preg_match('#^' . $definition->regex . '$#', $channelName, $matches) !== 1) {
                continue;
            }

            $params = [];

            foreach ($definition->paramNames as $name) {
                $params[$name] = $matches[$name];
            }

            return new ChannelMatch($definition->class, $definition->method, $definition->usesCurrentUser, $params);
        }

        return null;
    }

    /**
     * The `CacheableDiscoveryInterface` half of the compile path —
     * `fromArray()` below already satisfies the other half as-is, its
     * existing `self` return type being interface-compatible with
     * `static` for this `final` class.
     */
    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return BroadcastChannelDiscovery::discover($projectRoot)->toArray();
    }

    /**
     * @return list<array{pattern: string, regex: string, paramNames: list<string>, class: string, method: string, usesCurrentUser: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ChannelDefinition $definition): array => [
                'pattern' => $definition->pattern,
                'regex' => $definition->regex,
                'paramNames' => $definition->paramNames,
                'class' => $definition->class,
                'method' => $definition->method,
                'usesCurrentUser' => $definition->usesCurrentUser,
            ],
            $this->definitions,
        );
    }

    private const array CHANNEL_ENTRY_KEYS = ['pattern', 'regex', 'paramNames', 'class', 'method', 'usesCurrentUser'];

    /**
     * Validates every entry's own six required fields (and that no
     * *extra* field is present either) via
     * `Kinetis\Cache\Exception\ArtifactValidation` — the same discipline
     * `Kinetis\Cache\HttpCache`/`CommandCache::fromArray()` apply to
     * their own compiled entries — throwing
     * `Kinetis\Cache\Exception\InvalidCacheArtifactException` for
     * anything missing, extra, or wrong-typed, satisfying this class's
     * own `CacheableDiscoveryInterface::fromArray()` contract to throw a
     * classified exception for malformed data rather than a raw
     * `TypeError` escaping from inside `ChannelDefinition`'s own
     * constructor. Also re-runs the same duplicate/ambiguity checks
     * `register()` enforces at registration time — see
     * `assertNoConflictAgainst()` — rather than trusting a compiled
     * artifact still holds them; a reordered artifact is guaranteed to
     * hydrate into the identical canonical precedence regardless. A
     * pattern that violates the grammar itself (a duplicate placeholder
     * name, or more than one placeholder in a single segment) is caught
     * here too — `register()` can only reach this indirectly via
     * `InvalidChannelAuthorizerException`, so that specific exception
     * type is caught and re-thrown as the classified cache-artifact one
     * this method's own contract requires, rather than letting it escape
     * as the live-registration exception type a cache-hydration caller
     * has no reason to expect.
     *
     * `regex`/`paramNames` are validated for shape here (a stale or
     * corrupt artifact missing either must still be caught) but their
     * *values* are never trusted: this method always recomputes both
     * directly from `pattern`, so a cached artifact can never redefine
     * what a pattern actually authorizes by carrying a regex that's
     * drifted from it.
     *
     * Every entry is checked against every other entry already committed
     * from this same call — an exact duplicate pattern is always
     * rejected, **including** when its class/method/pattern triple is
     * byte-for-byte identical to an entry already committed: a repeated
     * entry is malformed cache data (the artifact this method's own
     * `compile()`/`toArray()` round trip produces can never contain one
     * in the first place), not a harmless re-registration, so there's no
     * leniency to extend it the way `register()` extends one to a class
     * registered twice live. This method never populates
     * `$registeredClasses` for any class an entry names, deliberately:
     * an artifact is untrusted data with no way to prove it holds every
     * one of a class's real attributed methods, so hydrating from one
     * must never be able to permanently suppress a class's remaining
     * methods the way marking it "registered" here would — see the
     * class-level docblock's own note on why `register()` alone earns
     * that marker, by reconciling against reflection.
     *
     * @param array<array-key, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        if (!array_is_list($data)) {
            throw InvalidCacheArtifactException::wrongFieldType('BroadcastChannelRegistry', '(root)', 'a list');
        }

        $registry = new self();
        /** @var list<ChannelDefinition> $committed */
        $committed = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw InvalidCacheArtifactException::malformedEntry('BroadcastChannelRegistry', 'a non-array entry');
            }

            ArtifactValidation::exactKeys($entry, 'BroadcastChannelRegistry channel', self::CHANNEL_ENTRY_KEYS);

            $pattern = ArtifactValidation::string($entry, 'BroadcastChannelRegistry channel', 'pattern');
            // Shape-checked, deliberately unused beyond that — see this
            // method's own docblock.
            ArtifactValidation::string($entry, 'BroadcastChannelRegistry channel', 'regex');
            ArtifactValidation::listOfStrings($entry, 'BroadcastChannelRegistry channel', 'paramNames');

            $class = ArtifactValidation::string($entry, 'BroadcastChannelRegistry channel', 'class');
            $method = ArtifactValidation::string($entry, 'BroadcastChannelRegistry channel', 'method');
            $usesCurrentUser = ArtifactValidation::bool($entry, 'BroadcastChannelRegistry channel', 'usesCurrentUser');

            try {
                [$regex, $paramNames] = self::compilePattern($pattern);
                $segments = self::decomposeSegments($pattern);

                self::assertNoConflictAgainst(
                    $committed,
                    $pattern,
                    $segments,
                    static fn (ChannelDefinition $existing) => throw InvalidCacheArtifactException::malformedEntry(
                        'BroadcastChannelRegistry',
                        "duplicate pattern \"{$pattern}\"",
                    ),
                    static fn (ChannelDefinition $existing) => throw InvalidCacheArtifactException::malformedEntry(
                        'BroadcastChannelRegistry',
                        "ambiguous pattern \"{$pattern}\" (matcher-equivalent to \"{$existing->pattern}\", "
                            . 'or an unresolvable overlap with it)',
                    ),
                );
            } catch (InvalidChannelAuthorizerException $e) {
                throw InvalidCacheArtifactException::malformedEntry(
                    'BroadcastChannelRegistry',
                    "pattern \"{$pattern}\" is malformed: {$e->getMessage()}",
                );
            }

            $committed[] = new ChannelDefinition($pattern, $regex, $paramNames, $class, $method, $usesCurrentUser);
        }

        usort($committed, self::compareBySpecificity(...));
        $registry->definitions = $committed;

        return $registry;
    }

    /**
     * Checks one candidate pattern ($pattern/$segments) against every
     * definition in $existing for a real conflict — shared by
     * `register()` and `fromArray()` so both apply the identical
     * invariant, whether a definition comes from live reflection or a
     * cached artifact. `$throwDuplicate`/`$throwAmbiguous` let each
     * caller raise its own exception type (a live registration failure
     * vs. a classified cache-artifact failure) for the identical
     * underlying check, rather than duplicating the comparison itself.
     *
     * Unconditional: an exact duplicate pattern string always throws
     * `$throwDuplicate`, regardless of whether the existing definition
     * claiming it shares the candidate's own class/method. Neither
     * caller ever needs an "already present" exemption from this method
     * specifically — `register()` already excludes $class's own prior
     * definitions from what it checks a freshly reflected one against
     * (see that method's own docblock), so this can never see a
     * candidate colliding with its own class's earlier state, and
     * `fromArray()` treats every repeated entry, identical triple
     * included, as malformed artifact data.
     *
     * `$regex`/`$paramNames` are never taken as a parameter here — both
     * `register()` and `fromArray()` always (re)compute them from
     * `$pattern` via `compilePattern()` before calling this, never
     * trusting a caller-supplied value; see `fromArray()`'s own docblock
     * for why that matters for a cached artifact specifically.
     * `compilePattern()`/`decomposeSegments()` can themselves throw
     * `InvalidChannelAuthorizerException` for a pattern that violates
     * the grammar (a duplicate placeholder name, or more than one
     * placeholder in one segment) before this method is ever reached —
     * `register()` lets that propagate directly; `fromArray()` catches
     * and reclassifies it, see that method's own docblock.
     *
     * Precedence: `$this->definitions` is kept in canonical precedence
     * order at all times — see `patternRelation()`'s own docblock for
     * exactly how two patterns are compared — both callers re-sort once
     * after committing their whole batch via `compareBySpecificity()`,
     * so `match()` itself needs no ordering logic of its own: iterating
     * `$this->definitions` in the order they're stored already tries the
     * most specific applicable pattern first, regardless of what order
     * they were registered or listed in a cache artifact. A pattern
     * whose relation to an existing one is `same` (matcher-equivalent,
     * differing only in placeholder names) or `incomparable` (they
     * genuinely overlap for some channel names, but neither is a subset
     * of the other) is rejected outright — there's no principled order
     * to assign it.
     *
     * @param list<ChannelDefinition> $existing
     * @param list<array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string}> $segments
     */
    private static function assertNoConflictAgainst(
        array $existing,
        string $pattern,
        array $segments,
        callable $throwDuplicate,
        callable $throwAmbiguous,
    ): void {
        foreach ($existing as $existingDefinition) {
            if ($existingDefinition->pattern === $pattern) {
                $throwDuplicate($existingDefinition);
            }

            $relation = self::patternRelation($segments, self::decomposeSegments($existingDefinition->pattern));

            if ($relation === 'same' || $relation === 'incomparable') {
                $throwAmbiguous($existingDefinition);
            }
        }
    }

    /**
     * Ranks two definitions by pattern precedence, most specific first —
     * deliberately **not** derived by re-running `patternRelation()` on
     * the specific pair being compared: `usort()` requires a strict
     * total order (reflexive, antisymmetric, and — critically —
     * transitive), and a per-pair relation check cannot guarantee that.
     * A single scalar computed once *per pattern* (`requiredLiteralByteCount()`),
     * compared as an ordinary number, is transitive by construction —
     * comparing scalars can never form a cycle, unlike combining
     * "subset-first" for one pair with an unrelated lexical order for
     * another. See `requiredLiteralByteCount()`'s own docblock for why
     * ranking by it, descending, still always places a strict subset
     * ahead of its superset. The pattern string itself is the final
     * tie-break, guaranteed unique across `$this->definitions` by the
     * exact-duplicate check `assertNoConflictAgainst()` already runs
     * before a pattern is ever added — so this comparator never reports
     * two distinct definitions as equal.
     */
    private static function compareBySpecificity(ChannelDefinition $a, ChannelDefinition $b): int
    {
        $byteCount = self::requiredLiteralByteCount($b->pattern) <=> self::requiredLiteralByteCount($a->pattern);

        return $byteCount !== 0 ? $byteCount : $a->pattern <=> $b->pattern;
    }

    /**
     * The total number of literal bytes $pattern's own segments
     * require — a literal segment counts every one of its own bytes; a
     * placeholder segment counts only its own prefix/suffix bytes,
     * never anything the placeholder itself can freely consume.
     *
     * Proven monotonic with strict containment, which is the one
     * property `compareBySpecificity()` actually depends on: whenever
     * `patternRelation()` would call $a's relation to $b `a_narrower`
     * (see that method's own docblock), every one of $a's segments is
     * either identical to or a stricter constraint than $b's
     * corresponding segment, with *at least one* segment strictly
     * stricter — and a stricter constraint always costs at least one
     * more required literal byte: a literal segment satisfying a
     * placeholder segment needs `len(prefix)+len(suffix)+1` bytes at
     * minimum (`literalSatisfies()`'s own check), strictly more than
     * that placeholder segment's own `len(prefix)+len(suffix)`; a
     * placeholder segment whose prefix and/or suffix strictly extends
     * another's is, by definition, strictly longer in at least one of
     * the two. So a strict subset's own total is always strictly
     * greater than its superset's, which is exactly what makes ranking
     * by this measure, descending, correct — not just a coincidence
     * that happens to hold for whichever examples were checked by hand.
     */
    private static function requiredLiteralByteCount(string $pattern): int
    {
        $count = 0;

        foreach (self::decomposeSegments($pattern) as $segment) {
            $count += $segment['type'] === 'literal'
                ? strlen($segment['value'])
                : strlen($segment['prefix']) + strlen($segment['suffix']);
        }

        return $count;
    }

    /**
     * The relationship between two *whole* patterns, derived entirely
     * from their per-segment relationships (see `segmentRelation()`) —
     * `disjoint` if any single segment can never simultaneously match
     * both patterns (an unconditional veto: if one segment can never
     * overlap, the whole patterns can never overlap, regardless of what
     * every other segment says); otherwise `same` if every segment is
     * `same`; `a_narrower`/`b_narrower` if every non-`same` segment
     * consistently favors the same side (`$a`'s language is then a
     * strict subset of `$b`'s, or vice versa, across the whole pattern
     * — a channel name matching the narrower one is provably guaranteed
     * to also match the broader one, segment by segment); `incomparable`
     * otherwise — either some segment is itself incomparable, or
     * different segments favor opposite sides, meaning neither pattern's
     * language contains the other's even though they do overlap.
     * Different segment counts are always `disjoint`, checked first — a
     * placeholder's own `[^.]+` can never itself contain a dot, so a
     * pattern's segment count is fixed entirely by its own literal dots,
     * and two patterns with different counts can never match the same
     * channel name at all.
     *
     * @param list<array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string}> $segmentsA
     * @param list<array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string}> $segmentsB
     * @return 'same'|'a_narrower'|'b_narrower'|'disjoint'|'incomparable'
     */
    private static function patternRelation(array $segmentsA, array $segmentsB): string
    {
        if (count($segmentsA) !== count($segmentsB)) {
            return 'disjoint';
        }

        $relations = [];

        foreach ($segmentsA as $index => $segmentA) {
            $relation = self::segmentRelation($segmentA, $segmentsB[$index]);

            if ($relation === 'disjoint') {
                return 'disjoint';
            }

            $relations[] = $relation;
        }

        $hasIncomparable = in_array('incomparable', $relations, true);
        $hasANarrower = in_array('a_narrower', $relations, true);
        $hasBNarrower = in_array('b_narrower', $relations, true);

        if ($hasIncomparable || ($hasANarrower && $hasBNarrower)) {
            return 'incomparable';
        }

        return match (true) {
            $hasANarrower => 'a_narrower',
            $hasBNarrower => 'b_narrower',
            default => 'same',
        };
    }

    /**
     * The relationship between two *segments* — the unit `patternRelation()`
     * builds its own whole-pattern verdict from. A placeholder segment's
     * language is fully characterized by its required prefix and suffix
     * (a literal segment is the degenerate case: an exact single string,
     * handled via `literalSatisfies()`), so containment between two
     * placeholder segments reduces to plain string prefix/suffix
     * comparison: $a's language is a subset of $b's exactly when $a's
     * own prefix requirement extends (or equals) $b's, and $a's own
     * suffix requirement extends (or equals) $b's — a longer, more
     * specific prefix/suffix can only ever narrow which strings satisfy
     * it, never widen it. Neither direction holding, while the two are
     * still compatible enough to genuinely overlap, is exactly the
     * `incomparable` case — see the class-level docblock for why the
     * grammar is deliberately narrow enough (at most one placeholder per
     * segment) for this simple comparison to be sound at all.
     *
     * @param array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string} $a
     * @param array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string} $b
     * @return 'same'|'a_narrower'|'b_narrower'|'disjoint'|'incomparable'
     */
    private static function segmentRelation(array $a, array $b): string
    {
        if ($a['type'] === 'literal' && $b['type'] === 'literal') {
            return $a['value'] === $b['value'] ? 'same' : 'disjoint';
        }

        if ($a['type'] === 'literal') {
            return self::literalSatisfies($a['value'], $b) ? 'a_narrower' : 'disjoint';
        }

        if ($b['type'] === 'literal') {
            return self::literalSatisfies($b['value'], $a) ? 'b_narrower' : 'disjoint';
        }

        // Both placeholder-containing: the string must start with BOTH
        // prefixes and end with BOTH suffixes simultaneously for any
        // overlap to exist at all, which is only possible when one
        // prefix extends the other and one suffix extends the other.
        $aPrefixExtendsB = str_starts_with($a['prefix'], $b['prefix']);
        $bPrefixExtendsA = str_starts_with($b['prefix'], $a['prefix']);
        $aSuffixExtendsB = str_ends_with($a['suffix'], $b['suffix']);
        $bSuffixExtendsA = str_ends_with($b['suffix'], $a['suffix']);

        if (!($aPrefixExtendsB || $bPrefixExtendsA) || !($aSuffixExtendsB || $bSuffixExtendsA)) {
            return 'disjoint';
        }

        $aIsSubsetOfB = $aPrefixExtendsB && $aSuffixExtendsB;
        $bIsSubsetOfA = $bPrefixExtendsA && $bSuffixExtendsA;

        return match (true) {
            $aIsSubsetOfB && $bIsSubsetOfA => 'same',
            $aIsSubsetOfB => 'a_narrower',
            $bIsSubsetOfA => 'b_narrower',
            default => 'incomparable',
        };
    }

    /**
     * Whether $literal — an exact, single-string segment — itself
     * satisfies a placeholder segment's own prefix/suffix/minimum-length
     * constraint (the placeholder must consume at least one character,
     * matching `[^.]+`'s own `+` quantifier). A literal segment's
     * language is always a strict subset of a placeholder segment's own
     * (infinite) language whenever it satisfies this constraint at
     * all — a placeholder can never match only that one exact string —
     * so `segmentRelation()` never needs to check the reverse direction.
     *
     * @param array{type: 'placeholder', prefix: string, suffix: string, name: string} $placeholderSegment
     */
    private static function literalSatisfies(string $literal, array $placeholderSegment): bool
    {
        $prefix = $placeholderSegment['prefix'];
        $suffix = $placeholderSegment['suffix'];

        if (!str_starts_with($literal, $prefix) || !str_ends_with($literal, $suffix)) {
            return false;
        }

        return strlen($literal) >= strlen($prefix) + strlen($suffix) + 1;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function compilePattern(string $pattern): array
    {
        $paramNames = [];
        $regexParts = [];

        foreach (self::decomposeSegments($pattern) as $index => $segment) {
            if ($index > 0) {
                $regexParts[] = '\.';
            }

            if ($segment['type'] === 'literal') {
                $regexParts[] = preg_quote($segment['value'], '#');

                continue;
            }

            $paramNames[] = $segment['name'];
            $regexParts[] = preg_quote($segment['prefix'], '#')
                . '(?P<' . $segment['name'] . '>[^.]+)'
                . preg_quote($segment['suffix'], '#');
        }

        return [implode('', $regexParts), $paramNames];
    }

    /**
     * Splits $pattern into its dot-separated segments, each classified
     * as a plain literal or as carrying exactly one `{name}` placeholder
     * (with its own literal prefix/suffix, both possibly empty) — see
     * the class-level docblock for why the grammar caps this at one
     * placeholder per segment. Throws
     * `InvalidChannelAuthorizerException` for a segment with more than
     * one placeholder, or a placeholder name reused anywhere else in the
     * same pattern (`orders.{id}.{id}`) — checked here, the one place
     * both `register()` and `fromArray()` actually decompose a pattern,
     * so a malformed one is rejected on both the live and cached path
     * identically, before it can ever reach `match()` as a duplicate
     * named PCRE capture group.
     *
     * @return list<array{type: 'literal', value: string}|array{type: 'placeholder', prefix: string, suffix: string, name: string}>
     */
    private static function decomposeSegments(string $pattern): array
    {
        $segments = [];
        $seenNames = [];

        foreach (explode('.', $pattern) as $segment) {
            preg_match_all('/\{([A-Za-z_]\w*)\}/', $segment, $matches, PREG_OFFSET_CAPTURE);
            $count = count($matches[0]);

            if ($count === 0) {
                $segments[] = ['type' => 'literal', 'value' => $segment];

                continue;
            }

            if ($count > 1) {
                throw InvalidChannelAuthorizerException::tooManyPlaceholdersInSegment($pattern, $segment);
            }

            /** @var string $name */
            $name = $matches[1][0][0];

            if (isset($seenNames[$name])) {
                throw InvalidChannelAuthorizerException::duplicatePlaceholderName($pattern, $name);
            }

            $seenNames[$name] = true;

            /** @var int $offset */
            $offset = $matches[0][0][1];
            /** @var string $fullMatch */
            $fullMatch = $matches[0][0][0];

            $segments[] = [
                'type' => 'placeholder',
                'prefix' => substr($segment, 0, $offset),
                'suffix' => substr($segment, $offset + strlen($fullMatch)),
                'name' => $name,
            ];
        }

        return $segments;
    }

    /**
     * @param list<string> $paramNames
     * @return bool whether the method's leading parameter is CurrentUserInterface
     */
    private function assertSignature(string $class, ReflectionMethod $method, string $pattern, array $paramNames): bool
    {
        $parameters = $method->getParameters();
        $usesCurrentUser = false;
        $offset = 0;

        if (isset($parameters[0])) {
            $type = $parameters[0]->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === CurrentUserInterface::class) {
                $usesCurrentUser = true;
                $offset = 1;
            }
        }

        $remaining = array_slice($parameters, $offset);

        if (count($remaining) !== count($paramNames)) {
            throw InvalidChannelAuthorizerException::wrongParameterCount(
                $class,
                $method->getName(),
                $pattern,
                count($paramNames),
                count($remaining),
            );
        }

        foreach ($remaining as $index => $parameter) {
            $expectedName = $paramNames[$index];

            if ($parameter->getName() !== $expectedName) {
                throw InvalidChannelAuthorizerException::parameterNameMismatch(
                    $class,
                    $method->getName(),
                    $pattern,
                    $expectedName,
                    $parameter->getName(),
                );
            }

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                throw InvalidChannelAuthorizerException::parameterNotString($class, $method->getName(), $parameter->getName());
            }
        }

        return $usesCurrentUser;
    }
}
