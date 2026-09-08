<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Broadcasting\Tests\DiscoveryFixtureProject\DiscoveredChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\AmbiguousOrderIdChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\DuplicatePatternAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\DuplicatePlaceholderNameChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\EmbeddedPlaceholderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\GuestChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\IntraBatchDuplicateChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\NonStringParameterAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrdersAdminChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TeamPresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterCountAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterNameAuthorizer;
use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * `orders.{orderId}` and `lobby` differ in segment count;
     * `orders.{orderId}` and `team.{teamId}` hold unequal literals in
     * their first position; `invites.{inviteId}` differs from both the
     * same way. No channel name reaches two of them, so all four
     * coexist.
     */
    public function test_patterns_that_no_channel_name_shares_all_coexist(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(TeamPresenceAuthorizer::class);
        $registry->register(GuestChannelAuthorizer::class);

        self::assertNotNull($registry->match('orders.42'));
        self::assertNotNull($registry->match('lobby'));
        self::assertNotNull($registry->match('team.7'));
        self::assertNotNull($registry->match('invites.abc'));
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

    /**
     * The three ways two patterns of the same segment count can overlap:
     * an identical pattern, a literal against a placeholder in the same
     * position, and two templates that differ only in a placeholder's
     * name. Each is rejected whichever class registers first, so
     * discovery order can never decide which authorizer a channel name
     * reaches.
     *
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function overlappingPairs(): iterable
    {
        yield 'identical patterns' => [OrderChannelAuthorizer::class, DuplicatePatternAuthorizer::class];
        yield 'literal against placeholder' => [OrderChannelAuthorizer::class, OrdersAdminChannelAuthorizer::class];
        yield 'renamed placeholder' => [OrderChannelAuthorizer::class, AmbiguousOrderIdChannelAuthorizer::class];
    }

    /**
     * @param class-string $first
     * @param class-string $second
     */
    #[DataProvider('overlappingPairs')]
    public function test_an_overlap_is_rejected_at_registration_in_both_orders(string $first, string $second): void
    {
        foreach ([[$first, $second], [$second, $first]] as [$earlier, $later]) {
            $registry = new BroadcastChannelRegistry();
            $registry->register($earlier);

            try {
                $registry->register($later);

                self::fail("Expected {$later} to conflict with {$earlier}.");
            } catch (InvalidChannelAuthorizerException $e) {
                self::assertStringContainsString('overlaps', $e->getMessage());
            }
        }
    }

    /**
     * @param class-string $first
     * @param class-string $second
     */
    #[DataProvider('overlappingPairs')]
    public function test_an_overlap_is_rejected_when_hydrating_an_artifact_in_both_entry_orders(string $first, string $second): void
    {
        $entries = [];

        foreach ([$first, $second] as $class) {
            $registry = new BroadcastChannelRegistry();
            $registry->register($class);
            $entries[] = $registry->toArray();
        }

        foreach ([$entries, array_reverse($entries)] as $order) {
            $this->assertArtifactRejected(array_merge(...$order));
        }
    }

    public function test_two_methods_on_one_class_claiming_the_same_pattern_are_rejected(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('overlaps');

        new BroadcastChannelRegistry()->register(IntraBatchDuplicateChannelAuthorizer::class);
    }

    public function test_registering_the_same_class_twice_conflicts_with_its_own_definitions(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('overlaps');

        $registry->register(OrderChannelAuthorizer::class);
    }

    public function test_a_placeholder_sharing_its_segment_with_literal_text_is_rejected_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('invalid segment "order-{id}"');

        new BroadcastChannelRegistry()->register(EmbeddedPlaceholderChannelAuthorizer::class);
    }

    public function test_a_duplicate_placeholder_name_is_rejected_at_registration(): void
    {
        $this->expectException(InvalidChannelAuthorizerException::class);
        $this->expectExceptionMessage('more than once');

        new BroadcastChannelRegistry()->register(DuplicatePlaceholderNameChannelAuthorizer::class);
    }

    /**
     * The grammar rejects the same patterns whether they arrive from a
     * live method or a cache artifact — the artifact path never reflects
     * a method, so it is the only place several of these can appear.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedPatterns(): iterable
    {
        yield 'embedded placeholder' => ['orders.order-{id}'];
        yield 'two placeholders in one segment' => ['orders.{a}-{b}'];
        yield 'unclosed brace' => ['orders.{id'];
        yield 'stray closing brace' => ['orders.id}'];
        yield 'empty trailing segment' => ['orders.'];
        yield 'empty inner segment' => ['orders..{id}'];
        yield 'empty pattern' => [''];
        yield 'repeated placeholder name' => ['orders.{id}.{id}'];
    }

    #[DataProvider('malformedPatterns')]
    public function test_a_malformed_pattern_is_rejected_when_hydrating_an_artifact(string $pattern): void
    {
        $this->assertArtifactRejected([
            ['pattern' => $pattern, 'class' => OrderChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false],
        ]);
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

        self::assertSame($registry->toArray(), $reloaded->toArray());

        foreach (['orders.42', 'lobby', 'team.7', 'unregistered'] as $channelName) {
            $original = $registry->match($channelName);
            $roundTripped = $reloaded->match($channelName);

            if ($original === null) {
                self::assertNull($roundTripped);

                continue;
            }

            self::assertNotNull($roundTripped);
            self::assertSame($original->class, $roundTripped->class);
            self::assertSame($original->method, $roundTripped->method);
            self::assertSame($original->usesCurrentUser, $roundTripped->usesCurrentUser);
            self::assertSame($original->params, $roundTripped->params);
        }
    }

    public function test_the_artifact_carries_only_the_pattern_class_method_and_current_user_flag(): void
    {
        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);

        self::assertSame([
            ['pattern' => 'orders.{orderId}', 'class' => OrderChannelAuthorizer::class, 'method' => 'authorizeOrder', 'usesCurrentUser' => true],
            ['pattern' => 'lobby', 'class' => OrderChannelAuthorizer::class, 'method' => 'authorizeLobby', 'usesCurrentUser' => false],
        ], $registry->toArray());
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
     * for malformed data — that is the one category BootSequence
     * classifies as "compile fresh instead", so a rejected artifact
     * recompiles rather than failing boot.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformedArtifacts(): iterable
    {
        $valid = ['pattern' => 'orders.{id}', 'class' => OrderChannelAuthorizer::class, 'method' => 'authorize', 'usesCurrentUser' => false];

        yield 'a non-list root' => [['pattern' => 'orders.{id}']];
        yield 'a non-array entry' => [['orders.{id}']];
        yield 'a missing field' => [[array_diff_key($valid, ['usesCurrentUser' => null])]];
        yield 'an extra field' => [[$valid + ['regex' => '(?P<id>[^.]+)']]];
        yield 'a non-string pattern' => [[['pattern' => 42] + $valid]];
        yield 'a non-bool current-user flag' => [[['usesCurrentUser' => 'yes'] + $valid]];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('malformedArtifacts')]
    public function test_from_array_rejects_a_malformed_artifact(array $data): void
    {
        $this->assertArtifactRejected($data);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function assertArtifactRejected(array $data): void
    {
        try {
            BroadcastChannelRegistry::fromArray($data);

            self::fail('Expected a classified cache-artifact exception.');
        } catch (CacheArtifactExceptionInterface $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }
}
