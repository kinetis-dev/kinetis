<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Exception\InvalidChannelAuthorizerException;
use Kinetis\Broadcasting\Tests\DiscoveryFixtureProject\DiscoveredChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\DuplicatePatternAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\NonStringParameterAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TeamPresenceAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterCountAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\WrongParameterNameAuthorizer;
use Kinetis\Cache\CacheableDiscoveryInterface;
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
}
