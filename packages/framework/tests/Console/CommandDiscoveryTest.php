<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\CommandDiscovery;
use PHPUnit\Framework\TestCase;

final class CommandDiscoveryTest extends TestCase
{
    public function test_discovers_the_built_in_build_and_mcp_serve_commands(): void
    {
        $registry = CommandDiscovery::discover(__DIR__ . '/Fixtures/does-not-exist');

        self::assertNotNull($registry->findCommand('build'));
        self::assertNotNull($registry->findCommand('mcp:serve'));
    }

    public function test_discovers_a_projects_own_commands_anywhere_under_its_psr4_root(): void
    {
        $registry = CommandDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

        self::assertNotNull($registry->findCommand('fixture:ping'));
        self::assertNotNull($registry->findCommand('fixture:unconventional'));
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $registry = CommandDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', ['Console']);

        self::assertNotNull($registry->findCommand('fixture:ping'));
        self::assertNull($registry->findCommand('fixture:unconventional'));
    }

    public function test_paths_falls_back_to_the_command_discovery_paths_env_var(): void
    {
        putenv('COMMAND_DISCOVERY_PATHS=Console');

        try {
            $registry = CommandDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

            self::assertNotNull($registry->findCommand('fixture:ping'));
            self::assertNull($registry->findCommand('fixture:unconventional'));
        } finally {
            putenv('COMMAND_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('COMMAND_DISCOVERY_PATHS=DoesNotExist');

        try {
            $registry = CommandDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', []);

            self::assertNotNull($registry->findCommand('fixture:ping'));
            self::assertNotNull($registry->findCommand('fixture:unconventional'));
        } finally {
            putenv('COMMAND_DISCOVERY_PATHS');
        }
    }

    public function test_discovering_against_the_real_kinetis_root_does_not_throw_on_a_duplicate_built_in_command(): void
    {
        // Developing Kinetis itself makes the project root and framework
        // root the same repository, so BuildCommand/McpServeCommand would
        // surface from classesInProject() *and* classesUnderFrameworkSegment()
        // — a real crash (InvalidCommandException: duplicate name) caught by
        // actually running bin/kinetis against this monorepo, not by review.
        $registry = CommandDiscovery::discover(dirname(__DIR__, 2));

        self::assertNotNull($registry->findCommand('build'));
        self::assertNotNull($registry->findCommand('mcp:serve'));
        self::assertNotNull($registry->findCommand('routes:list'));
    }
}
