<?php

declare(strict_types=1);

namespace Kinetis\Tests\Mcp;

use Kinetis\Mcp\McpDiscovery;
use PHPUnit\Framework\TestCase;

final class McpDiscoveryTest extends TestCase
{
    public function test_discovers_the_built_in_kinetis_docs_resource(): void
    {
        $registry = McpDiscovery::discover(__DIR__ . '/Fixtures/does-not-exist');

        self::assertNotNull($registry->findResource('kinetis://docs/index'));
    }

    public function test_discovers_a_projects_own_tools_anywhere_under_its_psr4_root(): void
    {
        $registry = McpDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

        self::assertNotNull($registry->findTool('discovered_ping'));
        self::assertNotNull($registry->findTool('unconventional_ping'));
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $registry = McpDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', ['Mcp']);

        self::assertNotNull($registry->findTool('discovered_ping'));
        self::assertNull($registry->findTool('unconventional_ping'));
    }

    public function test_paths_falls_back_to_the_mcp_discovery_paths_env_var(): void
    {
        putenv('MCP_DISCOVERY_PATHS=Mcp');

        try {
            $registry = McpDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

            self::assertNotNull($registry->findTool('discovered_ping'));
            self::assertNull($registry->findTool('unconventional_ping'));
        } finally {
            putenv('MCP_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('MCP_DISCOVERY_PATHS=DoesNotExist');

        try {
            $registry = McpDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', []);

            self::assertNotNull($registry->findTool('discovered_ping'));
            self::assertNotNull($registry->findTool('unconventional_ping'));
        } finally {
            putenv('MCP_DISCOVERY_PATHS');
        }
    }

    public function test_discovering_against_the_real_kinetis_root_does_not_duplicate_the_docs_resource(): void
    {
        // Same overlap CommandDiscoveryTest's identical regression test
        // covers: project root and framework root are the same repository
        // when developing Kinetis itself. McpRegistry::register() has no
        // duplicate-name check, so this wouldn't have thrown — it would
        // have silently registered every KinetisDocsResource method twice.
        $registry = McpDiscovery::discover(dirname(__DIR__, 2));

        $matches = array_filter($registry->resources(), static fn ($resource): bool => $resource->uri === 'kinetis://docs/index');

        self::assertCount(1, $matches);
    }
}
