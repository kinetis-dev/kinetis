<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcastChannelDiscovery;
use Kinetis\Broadcasting\Tests\DiscoveryFixtureProject\DiscoveredChannelAuthorizer;
use PHPUnit\Framework\TestCase;

final class BroadcastChannelDiscoveryTest extends TestCase
{
    public function test_discovers_a_projects_own_channel_authorizer_anywhere_under_its_psr4_root(): void
    {
        $registry = BroadcastChannelDiscovery::discover(__DIR__ . '/DiscoveryFixtureProject');

        $match = $registry->match('discovered.7');

        self::assertNotNull($match);
        self::assertSame(DiscoveredChannelAuthorizer::class, $match->class);
        self::assertSame('authorize', $match->method);
    }

    public function test_discovers_nothing_when_the_project_root_has_no_matching_classes(): void
    {
        $registry = BroadcastChannelDiscovery::discover(sys_get_temp_dir());

        self::assertNull($registry->match('discovered.7'));
    }

    public function test_discovering_against_the_real_framework_root_does_not_throw_or_duplicate(): void
    {
        // classesUnderFrameworkSegment('Broadcasting') always scans
        // packages/framework/src/Broadcasting (NamespaceScanner's own
        // frameworkRoot default), regardless of which package calls it —
        // so passing the real framework package root as $projectRoot here
        // is the exact overlap scenario (developing Kinetis itself, one
        // root scanned by both passes) every other Discovery class in
        // this project already guards against with a cross-pass $seen
        // dedup. Nothing under Kinetis\Broadcasting carries
        // #[BroadcastChannel] today, so this can only prove "runs without
        // error, no duplicate-pattern exception" — but it's the exact
        // call that would have thrown one without that dedup.
        $frameworkRoot = dirname(__DIR__, 2) . '/framework';

        $registry = BroadcastChannelDiscovery::discover($frameworkRoot);

        self::assertNull($registry->match('does-not-exist'));
    }
}
