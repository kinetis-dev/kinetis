<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Broadcasting\Tests\DiscoveryFixtureProject\DiscoveredChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\AmbiguousOrderIdChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\CycleBroadChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\CycleDisjointChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\CycleNarrowChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\DuplicatePatternAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\DuplicatePlaceholderNameChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\IncomparableSuffixOrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\IntraBatchDuplicateChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\NonStringParameterAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrdersAdminChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\PrefixOrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\ReconciledChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\SuffixBroadOrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\SuffixNarrowOrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TeamPresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TooManyPlaceholdersChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\ValidThenConflictingChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\ValidThenInvalidChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterCountAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterNameAuthorizer;
use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use PHPUnit\Framework\TestCase;

final class BroadcastChannelRegistryTest extends TestCase
{
    public function test_matches_a_placeholder_pattern_and_extracts_its_value(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        $match = $registry->match('orders.42');

        self::assertNotNull($match);
        self::assertSame(OrderChannelAuthorizer::class, $match->class);
        self::assertSame('authorizeOrder', $match->method);
        self::assertTrue($match->usesCurrentUser);
        self::assertSame(['orderId' => '42'], $match->params);
    }

    public function test_matches_a_pattern_with_no_placeholders_and_no_current_user(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        $match = $registry->match('lobby');

        self::assertNotNull($match);
        self::assertSame('authorizeLobby', $match->method);
        self::assertFalse($match->usesCurrentUser);
        self::assertSame([], $match->params);
    }

    public function test_a_placeholder_does_not_cross_a_dot_separator(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        self::assertNull($registry->match('orders.42.extra'));
    }

    public function test_an_unregistered_channel_name_matches_nothing(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        self::assertNull($registry->match('teams.7'));
    }

    public function test_registering_a_second_unrelated_class_keeps_both_patterns_matchable(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(TeamPresenceAuthorizer::class);

        self::assertNotNull($registry->match('orders.42'));
        self::assertNotNull($registry->match('team.7'));
    }

    public function test_a_method_with_too_few_parameters_throws_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('WrongParameterCountAuthorizer::authorize()');

        new BroadcastChannelRegistry()->register(WrongParameterCountAuthorizer::class);
    }

    public function test_a_mismatched_parameter_name_throws_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('must be named "orderId"');

        new BroadcastChannelRegistry()->register(WrongParameterNameAuthorizer::class);
    }

    public function test_a_non_string_parameter_throws_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('must be typed string');

        new BroadcastChannelRegistry()->register(NonStringParameterAuthorizer::class);
    }

    public function test_a_duplicate_pattern_across_two_classes_throws_at_registration(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('is already registered by');

        $registry->register(DuplicatePatternAuthorizer::class);
    }

    /**
     * `orders.admin` (fully literal) and `orders.{orderId}` (a
     * placeholder in the same position) both match the channel name
     * `orders.admin` — the literal pattern must always win, regardless
     * of which class happened to be registered first.
     */
    public function test_a_more_specific_literal_pattern_beats_an_overlapping_placeholder_regardless_of_registration_order(): void
    {
        $placeholderFirst = new BroadcastChannelRegistry();
        $placeholderFirst->register(OrderChannelAuthorizer::class);
        $placeholderFirst->register(OrdersAdminChannelAuthorizer::class);

        $literalFirst = new BroadcastChannelRegistry();
        $literalFirst->register(OrdersAdminChannelAuthorizer::class);
        $literalFirst->register(OrderChannelAuthorizer::class);

        foreach ([$placeholderFirst, $literalFirst] as $registry) {
            $match = $registry->match('orders.admin');

            self::assertNotNull($match);
            self::assertSame(OrdersAdminChannelAuthorizer::class, $match->class);
        }

        // orders.42 is untouched -- it never matches orders.admin's own
        // literal pattern, so the placeholder authorizer still handles
        // every channel name that isn't specifically "orders.admin".
        $match = $placeholderFirst->match('orders.42');
        self::assertNotNull($match);
        self::assertSame(OrderChannelAuthorizer::class, $match->class);
    }

    /**
     * The same precedence must hold after a cache round trip, in both
     * the natural artifact order and reversed — a compiled artifact's
     * own entry order must never be able to change which definition
     * wins.
     */
    public function test_a_more_specific_literal_pattern_beats_an_overlapping_placeholder_regardless_of_cache_entry_order(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(OrdersAdminChannelAuthorizer::class);

        $naturalOrder = $registry->toArray();
        $reversedOrder = array_reverse($naturalOrder);

        foreach ([$naturalOrder, $reversedOrder] as $entries) {
            $reloaded = BroadcastChannelRegistry::fromArray($entries);
            $match = $reloaded->match('orders.admin');

            self::assertNotNull($match);
            self::assertSame(OrdersAdminChannelAuthorizer::class, $match->class);
        }
    }

    /**
     * `orders.{orderId}` (OrderChannelAuthorizer) and `orders.{id}`
     * (a different placeholder name, otherwise structurally identical)
     * match exactly the same channel names with identical specificity —
     * there is no principled way to prefer one, so registration fails
     * rather than one silently winning.
     */
    public function test_semantically_equivalent_placeholder_patterns_with_different_names_are_rejected(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('cannot be distinguished from the already-registered');

        $registry->register(AmbiguousOrderIdChannelAuthorizer::class);
    }

    /**
     * The identical ambiguity check applies when hydrating from a cache
     * artifact — a compiled artifact carrying two matcher-equivalent
     * patterns must throw the package's own classified cache-artifact
     * exception, not silently pick whichever entry happens to come
     * first.
     */
    public function test_semantically_equivalent_placeholder_patterns_are_rejected_from_a_cache_artifact_too(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('ambiguous pattern');

        BroadcastChannelRegistry::fromArray([
            ['pattern' => 'orders.{orderId}', 'regex' => '(?P<orderId>[^.]+)', 'paramNames' => ['orderId'], 'class' => OrderChannelAuthorizer::class, 'method' => 'authorizeOrder', 'usesCurrentUser' => true],
            ['pattern' => 'orders.{id}', 'regex' => '(?P<id>[^.]+)', 'paramNames' => ['id'], 'class' => AmbiguousOrderIdChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false],
        ]);
    }

    /**
     * `orders.{orderId}` and `team.{teamId}` share the identical
     * per-segment specificity shape (one literal segment, one
     * placeholder) but genuinely different literal content — they can
     * never both match the same channel name, so this is not the
     * ambiguity the other tests exercise; both must keep working
     * exactly as before.
     */
    public function test_ordinary_non_overlapping_patterns_with_the_same_specificity_shape_are_unaffected(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(TeamPresenceAuthorizer::class);

        $orderMatch = $registry->match('orders.42');
        $teamMatch = $registry->match('team.7');

        self::assertNotNull($orderMatch);
        self::assertSame(OrderChannelAuthorizer::class, $orderMatch->class);
        self::assertNotNull($teamMatch);
        self::assertSame(TeamPresenceAuthorizer::class, $teamMatch->class);
    }

    /**
     * An artifact whose own cached "regex" field has drifted from what
     * $pattern would actually compile to must never be trusted for
     * authorization — fromArray() always recomputes it from $pattern
     * directly, so a tampered or stale cached regex can't redefine what
     * a channel name is allowed to match.
     */
    public function test_from_array_never_trusts_a_drifted_cached_regex(): void
    {
        $reloaded = BroadcastChannelRegistry::fromArray([
            [
                'pattern' => 'orders.{orderId}',
                // Deliberately wrong -- would match anything at all,
                // including a channel name the real pattern rejects.
                'regex' => '.*',
                'paramNames' => ['orderId'],
                'class' => OrderChannelAuthorizer::class,
                'method' => 'authorizeOrder',
                'usesCurrentUser' => true,
            ],
        ]);

        self::assertNull($reloaded->match('orders.42.extra'));
        self::assertNotNull($reloaded->match('orders.42'));
    }

    /**
     * `orders.{id}-final-draft` is a strict subset of `orders.{id}-draft`
     * (every channel ending in "-final-draft" also ends in "-draft") —
     * the narrower one must always win, regardless of registration
     * order, and neither is simply "more literal bytes" than the other
     * in any way a byte-count heuristic could get right by coincidence.
     */
    public function test_a_suffix_narrower_placeholder_pattern_beats_a_suffix_broader_one_regardless_of_registration_order(): void
    {
        $broadFirst = new BroadcastChannelRegistry();
        $broadFirst->register(SuffixBroadOrderChannelAuthorizer::class);
        $broadFirst->register(SuffixNarrowOrderChannelAuthorizer::class);

        $narrowFirst = new BroadcastChannelRegistry();
        $narrowFirst->register(SuffixNarrowOrderChannelAuthorizer::class);
        $narrowFirst->register(SuffixBroadOrderChannelAuthorizer::class);

        foreach ([$broadFirst, $narrowFirst] as $registry) {
            $match = $registry->match('orders.42-final-draft');

            self::assertNotNull($match);
            self::assertSame(SuffixNarrowOrderChannelAuthorizer::class, $match->class);
        }

        // A channel that only satisfies the broader pattern still
        // resolves to it correctly.
        $match = $broadFirst->match('orders.42-draft');
        self::assertNotNull($match);
        self::assertSame(SuffixBroadOrderChannelAuthorizer::class, $match->class);
    }

    /**
     * The identical precedence must hold after a cache round trip, in
     * both the natural artifact order and reversed.
     */
    public function test_a_suffix_narrower_placeholder_pattern_beats_a_suffix_broader_one_regardless_of_cache_entry_order(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(SuffixBroadOrderChannelAuthorizer::class);
        $registry->register(SuffixNarrowOrderChannelAuthorizer::class);

        $naturalOrder = $registry->toArray();
        $reversedOrder = array_reverse($naturalOrder);

        foreach ([$naturalOrder, $reversedOrder] as $entries) {
            $reloaded = BroadcastChannelRegistry::fromArray($entries);
            $match = $reloaded->match('orders.42-final-draft');

            self::assertNotNull($match);
            self::assertSame(SuffixNarrowOrderChannelAuthorizer::class, $match->class);
        }
    }

    /**
     * `orders.archived-{id}` and `orders.{id}-2024` genuinely overlap
     * (both match `orders.archived-2024`) without either one containing
     * the other — there's no principled winner, so registration must
     * fail rather than a byte-count or lexical tie-break silently
     * picking one.
     */
    public function test_an_incomparable_overlap_is_rejected_at_registration_in_both_orders(): void
    {
        foreach ([[PrefixOrderChannelAuthorizer::class, IncomparableSuffixOrderChannelAuthorizer::class],
            [IncomparableSuffixOrderChannelAuthorizer::class, PrefixOrderChannelAuthorizer::class]] as [$first, $second]) {
            $registry = new BroadcastChannelRegistry();
            $registry->register($first);

            try {
                $registry->register($second);

                self::fail('Expected InvalidChannelAuthorizerException.');
            } catch (InvalidChannelAuthorizerException $e) {
                self::assertStringContainsString('cannot be distinguished from', $e->getMessage());
            }
        }
    }

    /**
     * The identical incomparable-overlap rejection applies when
     * hydrating from a cache artifact, in both entry orders, and throws
     * the classified cache-artifact exception rather than
     * `InvalidChannelAuthorizerException` directly.
     */
    public function test_an_incomparable_overlap_is_rejected_from_a_cache_artifact_in_both_entry_orders(): void
    {
        $entryA = ['pattern' => 'orders.archived-{id}', 'regex' => '(?P<id>[^.]+)', 'paramNames' => ['id'], 'class' => PrefixOrderChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false];
        $entryB = ['pattern' => 'orders.{id}-2024', 'regex' => '(?P<id>[^.]+)', 'paramNames' => ['id'], 'class' => IncomparableSuffixOrderChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false];

        foreach ([[$entryA, $entryB], [$entryB, $entryA]] as $entries) {
            try {
                BroadcastChannelRegistry::fromArray($entries);

                self::fail('Expected CacheArtifactExceptionInterface.');
            } catch (CacheArtifactExceptionInterface $e) {
                self::assertStringContainsString('ambiguous pattern', $e->getMessage());
            }
        }
    }

    /**
     * A real PHP method cannot declare two parameters both named $id, so
     * the live path can only ever reach this via compilePattern() itself
     * rejecting the pattern before assertSignature() ever runs.
     */
    public function test_a_duplicate_placeholder_name_is_rejected_at_registration_before_matching(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('more than once');

        new BroadcastChannelRegistry()->register(DuplicatePlaceholderNameChannelAuthorizer::class);
    }

    /**
     * `fromArray()` bypasses signature reflection entirely, so a
     * duplicate placeholder name reaching it would otherwise hydrate
     * into a PCRE regex with two capture groups sharing one name — must
     * be rejected as a classified cache-artifact error before match()
     * ever runs, not surface as a match-time warning or failure.
     */
    public function test_a_duplicate_placeholder_name_is_rejected_from_a_cache_artifact_before_matching(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('more than once');

        BroadcastChannelRegistry::fromArray([
            ['pattern' => 'orders.{id}.{id}', 'regex' => '(?P<id>[^.]+)\.(?P<id>[^.]+)', 'paramNames' => ['id', 'id'], 'class' => DuplicatePlaceholderNameChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false],
        ]);
    }

    public function test_more_than_one_placeholder_in_a_segment_is_rejected_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('more than one placeholder');

        new BroadcastChannelRegistry()->register(TooManyPlaceholdersChannelAuthorizer::class);
    }

    public function test_more_than_one_placeholder_in_a_segment_is_rejected_from_a_cache_artifact(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('more than one placeholder');

        BroadcastChannelRegistry::fromArray([
            ['pattern' => 'orders.{a}-{b}', 'regex' => '(?P<a>[^.]+)-(?P<b>[^.]+)', 'paramNames' => ['a', 'b'], 'class' => TooManyPlaceholdersChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false],
        ]);
    }

    /**
     * @param list<mixed> $items
     * @return iterable<list<mixed>>
     */
    private static function permutationsOf(array $items): iterable
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $index => $item) {
            $rest = $items;
            unset($rest[$index]);

            foreach (self::permutationsOf(array_values($rest)) as $permutation) {
                yield array_merge([$item], $permutation);
            }
        }
    }

    /**
     * A comparator that combines "subset wins" for an overlapping pair
     * with an unrelated tie-break for a disjoint pair can form a cycle
     * — `usort()` requires a genuine total order (transitive across
     * *every* pair, not just the ones that overlap), and a cycle makes
     * its result depend on insertion order, which is exactly the
     * order-dependent-authorization failure this whole precedence
     * mechanism exists to prevent. This fixture set exercises that:
     * CycleNarrowChannelAuthorizer (`x.{id}za`) is a strict subset of
     * CycleBroadChannelAuthorizer (`x.{id}a`), and
     * CycleDisjointChannelAuthorizer (`x.{id}m.more`) is disjoint from
     * both by segment count. Every one of the six registration
     * permutations must produce the identical canonical `toArray()`
     * order and always resolve the narrow authorizer for a channel
     * matching both it and the broad one — this doubles as the
     * comparator-law regression itself: any non-transitivity would
     * make at least one permutation disagree with the others, so this
     * is verified through the public API rather than reflection into
     * the private comparator, matching how the rest of this codebase
     * tests behavior rather than implementation.
     */
    public function test_a_cycle_triggering_pattern_set_produces_the_same_canonical_order_and_correct_match_across_every_registration_permutation(): void
    {
        $classes = [
            CycleNarrowChannelAuthorizer::class,
            CycleBroadChannelAuthorizer::class,
            CycleDisjointChannelAuthorizer::class,
        ];

        $canonicalOrder = null;

        foreach (self::permutationsOf($classes) as $order) {
            $registry = new BroadcastChannelRegistry();

            foreach ($order as $class) {
                $registry->register($class);
            }

            $match = $registry->match('x.fooza');
            self::assertNotNull($match);
            self::assertSame(CycleNarrowChannelAuthorizer::class, $match->class);

            $resultingOrder = array_column($registry->toArray(), 'class');
            $canonicalOrder ??= $resultingOrder;
            self::assertSame($canonicalOrder, $resultingOrder);
        }
    }

    /**
     * The identical proof through every permutation of the same three
     * patterns as cache-artifact entries, not just registration order.
     */
    public function test_a_cycle_triggering_pattern_set_produces_the_same_canonical_order_and_correct_match_across_every_cache_entry_permutation(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(CycleNarrowChannelAuthorizer::class);
        $registry->register(CycleBroadChannelAuthorizer::class);
        $registry->register(CycleDisjointChannelAuthorizer::class);
        $entries = $registry->toArray();

        $canonicalOrder = null;

        foreach (self::permutationsOf($entries) as $order) {
            $reloaded = BroadcastChannelRegistry::fromArray($order);

            $match = $reloaded->match('x.fooza');
            self::assertNotNull($match);
            self::assertSame(CycleNarrowChannelAuthorizer::class, $match->class);

            $resultingOrder = array_column($reloaded->toArray(), 'class');
            $canonicalOrder ??= $resultingOrder;
            self::assertSame($canonicalOrder, $resultingOrder);
        }
    }

    public function test_registering_a_non_registrable_class_directly_throws(): void
    {
        // Discovery never reaches this — NamespaceScanner filters via
        // AttributeScope::isRegistrable() before register() is ever
        // called — so a bad class here can only mean a direct,
        // hand-written register() call, which should fail loudly, the
        // same as EventListenerRegistry::register()/McpRegistry::register()
        // both already do for the identical input.
        $this->expectException(\Kinetis\Reflection\Exception\AttributeScopeException::class);

        new BroadcastChannelRegistry()->register(\Kinetis\Broadcasting\BroadcasterInterface::class);
    }

    public function test_a_round_trip_through_to_array_and_from_array_matches(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(TeamPresenceAuthorizer::class);

        $reloaded = BroadcastChannelRegistry::fromArray($registry->toArray());

        $original = $registry->match('orders.42');
        $roundTripped = $reloaded->match('orders.42');

        self::assertNotNull($original);
        self::assertNotNull($roundTripped);
        self::assertSame($original->class, $roundTripped->class);
        self::assertSame($original->method, $roundTripped->method);
        self::assertSame($original->params, $roundTripped->params);
    }

    public function test_implements_the_frameworks_cacheable_discovery_interface(): void
    {
        self::assertInstanceOf(CacheableDiscoveryInterface::class, new BroadcastChannelRegistry());
    }

    public function test_compile_delegates_to_discovery_and_reduces_it_to_plain_data(): void
    {
        $data = BroadcastChannelRegistry::compile(__DIR__ . '/DiscoveryFixtureProject');

        $reloaded = BroadcastChannelRegistry::fromArray($data);
        $match = $reloaded->match('discovered.7');

        self::assertNotNull($match);
        self::assertSame(DiscoveredChannelAuthorizer::class, $match->class);
    }

    /**
     * fromArray()'s CacheableDiscoveryInterface contract requires
     * throwing something implementing CacheArtifactExceptionInterface
     * for malformed data — verified directly against real, malformed
     * shapes, not assumed from the interface's own docblock alone.
     */
    public function test_from_array_rejects_a_non_list_root(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        BroadcastChannelRegistry::fromArray(['pattern' => 'orders.{id}']);
    }

    public function test_from_array_rejects_an_entry_missing_a_required_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        BroadcastChannelRegistry::fromArray([
            ['pattern' => 'orders.{id}', 'regex' => '(?P<id>[^.]+)', 'paramNames' => ['id'], 'class' => OrderChannelAuthorizer::class, 'method' => 'authorize'],
        ]);
    }

    public function test_from_array_rejects_a_non_string_param_name(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        BroadcastChannelRegistry::fromArray([
            ['pattern' => 'orders.{id}', 'regex' => '(?P<id>[^.]+)', 'paramNames' => [42], 'class' => OrderChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false],
        ]);
    }

    public function test_a_valid_method_followed_by_an_invalid_one_leaves_neither_registered(): void
    {
        $registry = new BroadcastChannelRegistry();

        try {
            $registry->register(ValidThenInvalidChannelAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException) {
            // expected
        }

        self::assertNull($registry->match('valid-owner.1'));
        self::assertNull($registry->match('invalid-owner.1'));
        self::assertSame([], $registry->toArray());
    }

    public function test_an_intra_batch_conflict_leaves_no_earlier_method_from_that_class_registered(): void
    {
        $registry = new BroadcastChannelRegistry();

        try {
            $registry->register(IntraBatchDuplicateChannelAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException $e) {
            self::assertStringContainsString('is already registered by', $e->getMessage());
        }

        self::assertNull($registry->match('batch-owner.1'));
        self::assertSame([], $registry->toArray());
    }

    /**
     * The conflict here is against the registry's own already-committed
     * state (OrderChannelAuthorizer's `orders.{orderId}`), not against
     * anything ValidThenConflictingChannelAuthorizer itself is staging —
     * covers both "an existing-registry conflict leaves no earlier
     * method behind" and "a genuinely different owner of the same
     * pattern still throws" in one real scenario.
     */
    public function test_an_existing_registry_conflict_leaves_no_earlier_method_from_the_new_class_registered(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        try {
            $registry->register(ValidThenConflictingChannelAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException $e) {
            self::assertStringContainsString('is already registered by', $e->getMessage());
        }

        self::assertNull($registry->match('unique-owner.1'));

        $match = $registry->match('orders.42');
        self::assertNotNull($match);
        self::assertSame(OrderChannelAuthorizer::class, $match->class);
    }

    public function test_registering_the_same_class_twice_is_a_no_op(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(OrderChannelAuthorizer::class);

        self::assertCount(2, $registry->toArray());
        self::assertNotNull($registry->match('orders.42'));
        self::assertNotNull($registry->match('lobby'));
    }

    public function test_precedence_order_is_unchanged_after_a_failed_or_repeated_registration(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(OrdersAdminChannelAuthorizer::class);

        $before = $registry->toArray();

        try {
            $registry->register(WrongParameterCountAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException) {
            // expected
        }

        $registry->register(OrderChannelAuthorizer::class);

        self::assertSame($before, $registry->toArray());

        $match = $registry->match('orders.admin');
        self::assertNotNull($match);
        self::assertSame(OrdersAdminChannelAuthorizer::class, $match->class);
    }

    /**
     * A cache artifact is untrusted data: the artifact `compile()`/
     * `toArray()` themselves would ever produce can never contain a
     * repeated entry, so one appearing anyway is malformed, not a
     * harmless re-registration — including when its class/method/
     * pattern triple is byte-for-byte identical to an entry already
     * committed from earlier in the same artifact.
     */
    public function test_from_array_rejects_a_repeated_identical_entry(): void
    {
        $source = new BroadcastChannelRegistry();
        $source->register(OrderChannelAuthorizer::class);
        $orderEntry = current(array_filter(
            $source->toArray(),
            static fn (array $entry): bool => $entry['method'] === 'authorizeOrder',
        ));

        self::assertIsArray($orderEntry);

        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('duplicate pattern');

        BroadcastChannelRegistry::fromArray([$orderEntry, $orderEntry]);
    }

    /**
     * A registry hydrated from an artifact that only ever named one of
     * OrderChannelAuthorizer's two methods must still gain the missing
     * one (`authorizeLobby`'s `lobby` pattern) once that class is
     * genuinely `register()`ed live — proving the class was never marked
     * "fully registered" purely because one artifact row named it.
     */
    public function test_registering_live_after_hydrating_only_part_of_a_class_from_an_artifact_adds_the_missing_definitions(): void
    {
        $source = new BroadcastChannelRegistry();
        $source->register(OrderChannelAuthorizer::class);
        $orderEntry = current(array_filter(
            $source->toArray(),
            static fn (array $entry): bool => $entry['method'] === 'authorizeOrder',
        ));

        self::assertIsArray($orderEntry);

        $registry = BroadcastChannelRegistry::fromArray([$orderEntry]);
        $registry->register(OrdersAdminChannelAuthorizer::class);
        $registry->register(OrderChannelAuthorizer::class);

        self::assertCount(3, $registry->toArray());

        $orderMatch = $registry->match('orders.7');
        $lobbyMatch = $registry->match('lobby');
        $adminMatch = $registry->match('orders.admin');

        self::assertNotNull($orderMatch);
        self::assertNotNull($lobbyMatch);
        self::assertNotNull($adminMatch);
        self::assertSame('authorizeOrder', $orderMatch->method);
        self::assertSame('authorizeLobby', $lobbyMatch->method);
        self::assertSame(OrdersAdminChannelAuthorizer::class, $adminMatch->class);
    }

    /**
     * A hydrated entry whose `usesCurrentUser` disagrees with what the
     * real method signature says must never survive a live registration
     * unchanged — reflection is always authoritative over cached
     * metadata, so the wrong cached value is corrected in place, not
     * preserved because the class/method/pattern triple happened to
     * match.
     */
    public function test_a_hydrated_entry_with_wrong_metadata_is_reconciled_to_reflected_truth_on_live_registration(): void
    {
        $entry = [
            'pattern' => 'orders.{orderId}',
            'regex' => '(?P<orderId>[^.]+)',
            'paramNames' => ['orderId'],
            'class' => OrderChannelAuthorizer::class,
            'method' => 'authorizeOrder',
            'usesCurrentUser' => false,
        ];

        $registry = BroadcastChannelRegistry::fromArray([$entry]);
        $registry->register(OrderChannelAuthorizer::class);

        $match = $registry->match('orders.42');
        self::assertNotNull($match);
        self::assertTrue($match->usesCurrentUser);
        self::assertCount(2, $registry->toArray());
    }

    /**
     * A stray artifact row naming a real class but a method that class
     * doesn't actually declare must never survive that class's real live
     * registration — live reflection is authoritative for the class's
     * whole definition set, so a row it can't account for is removed,
     * not merely shadowed by the two real ones added alongside it.
     */
    public function test_a_bogus_artifact_row_naming_a_real_class_is_removed_by_live_registration(): void
    {
        $bogusEntry = [
            'pattern' => 'bogus.{id}',
            'regex' => '(?P<id>[^.]+)',
            'paramNames' => ['id'],
            'class' => OrderChannelAuthorizer::class,
            'method' => 'nonexistentMethod',
            'usesCurrentUser' => false,
        ];

        $registry = BroadcastChannelRegistry::fromArray([$bogusEntry]);
        $registry->register(OrderChannelAuthorizer::class);

        self::assertCount(2, $registry->toArray());
        self::assertNull($registry->match('bogus.1'));
        self::assertNotNull($registry->match('orders.42'));
        self::assertNotNull($registry->match('lobby'));
    }

    /**
     * A hydrated row claiming an old pattern for a method the class
     * still attributes today — just under a different pattern string —
     * must not survive live registration: only the pattern the method's
     * own `#[BroadcastChannel]` attribute currently declares remains.
     */
    public function test_a_stale_pattern_for_a_still_attributed_method_is_removed_by_live_registration(): void
    {
        $staleEntry = [
            'pattern' => 'reconciled-legacy.{id}',
            'regex' => '(?P<id>[^.]+)',
            'paramNames' => ['id'],
            'class' => ReconciledChannelAuthorizer::class,
            'method' => 'authorizeCurrent',
            'usesCurrentUser' => false,
        ];

        $registry = BroadcastChannelRegistry::fromArray([$staleEntry]);
        $registry->register(ReconciledChannelAuthorizer::class);

        self::assertCount(1, $registry->toArray());
        self::assertNull($registry->match('reconciled-legacy.1'));

        $match = $registry->match('reconciled.1');
        self::assertNotNull($match);
        self::assertSame('authorizeCurrent', $match->method);
    }

    /**
     * A hydrated row for a real method the class no longer attributes at
     * all must not survive live registration either — the stale
     * authorization policy it represents is removed, not merely
     * shadowed by the class's real current definitions.
     */
    public function test_a_hydrated_row_for_a_no_longer_attributed_method_is_removed_by_live_registration(): void
    {
        $staleEntry = [
            'pattern' => 'reconciled-old-method.{id}',
            'regex' => '(?P<id>[^.]+)',
            'paramNames' => ['id'],
            'class' => ReconciledChannelAuthorizer::class,
            'method' => 'legacyAuthorize',
            'usesCurrentUser' => false,
        ];

        $registry = BroadcastChannelRegistry::fromArray([$staleEntry]);
        $registry->register(ReconciledChannelAuthorizer::class);

        self::assertCount(1, $registry->toArray());
        self::assertNull($registry->match('reconciled-old-method.1'));

        $match = $registry->match('reconciled.1');
        self::assertNotNull($match);
        self::assertSame('authorizeCurrent', $match->method);
    }

    /**
     * A conflict discovered while reconciling one class's own batch must
     * leave every other class's already-hydrated state exactly as it
     * was — nothing about a failed registration for one class may
     * disturb what's already committed for a completely different one.
     */
    public function test_a_conflict_during_reconciliation_leaves_pre_existing_hydrated_state_untouched(): void
    {
        $source = new BroadcastChannelRegistry();
        $source->register(OrderChannelAuthorizer::class);
        $orderEntry = current(array_filter(
            $source->toArray(),
            static fn (array $entry): bool => $entry['method'] === 'authorizeOrder',
        ));

        self::assertIsArray($orderEntry);

        $registry = BroadcastChannelRegistry::fromArray([$orderEntry]);

        try {
            $registry->register(DuplicatePatternAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException $e) {
            self::assertStringContainsString('is already registered by', $e->getMessage());
        }

        self::assertCount(1, $registry->toArray());

        $match = $registry->match('orders.42');
        self::assertNotNull($match);
        self::assertSame(OrderChannelAuthorizer::class, $match->class);
        self::assertSame('authorizeOrder', $match->method);
    }

    /**
     * A failed live registration must leave every preexisting row
     * untouched — including a row already hydrated for the very class
     * whose registration just failed, not only rows belonging to other
     * classes. The replacement is computed entirely in local variables
     * and only ever assigned to `$this->definitions` once the whole
     * batch validates, so a throw partway through a class's own methods
     * never reaches that assignment at all.
     */
    public function test_a_failed_live_registration_leaves_every_preexisting_row_including_the_target_classs_own_unchanged(): void
    {
        $orderSource = new BroadcastChannelRegistry();
        $orderSource->register(OrderChannelAuthorizer::class);
        $orderEntry = current(array_filter(
            $orderSource->toArray(),
            static fn (array $entry): bool => $entry['method'] === 'authorizeOrder',
        ));

        self::assertIsArray($orderEntry);

        $ownEntry = [
            'pattern' => 'unique-owner.{id}',
            'regex' => '(?P<id>[^.]+)',
            'paramNames' => ['id'],
            'class' => ValidThenConflictingChannelAuthorizer::class,
            'method' => 'authorizeUnique',
            'usesCurrentUser' => false,
        ];

        $registry = BroadcastChannelRegistry::fromArray([$orderEntry, $ownEntry]);
        $before = $registry->toArray();

        try {
            $registry->register(ValidThenConflictingChannelAuthorizer::class);

            self::fail('Expected InvalidChannelAuthorizerException.');
        } catch (InvalidChannelAuthorizerException $e) {
            self::assertStringContainsString('is already registered by', $e->getMessage());
        }

        self::assertSame($before, $registry->toArray());
    }
}
