<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Tests\Cache\Fixtures\Http\AbstractScannedController;
use PHPUnit\Framework\TestCase;

final class NamespaceScannerTest extends TestCase
{
    public function test_finds_classes_anywhere_under_the_projects_own_psr4_root(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures'));

        self::assertContains('Kinetis\Tests\Cache\Fixtures\Console\DiscoveredPingCommand', $classes);
        self::assertContains('Kinetis\Tests\Cache\Fixtures\Mcp\DiscoveredToolController', $classes);
        self::assertContains('Kinetis\Tests\Cache\Fixtures\Http\DiscoveredPingController', $classes);
    }

    public function test_finds_a_class_in_a_deliberately_unconventional_location(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures'));

        self::assertContains('Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalPingCommand', $classes);
    }

    public function test_paths_restricts_the_scan_to_the_given_sub_paths(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures', ['Console']));

        self::assertContains('Kinetis\Tests\Cache\Fixtures\Console\DiscoveredPingCommand', $classes);
        self::assertNotContains('Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalPingCommand', $classes);
    }

    public function test_yields_nothing_for_a_path_with_no_matching_directory(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures', ['DoesNotExist']));

        self::assertSame([], $classes);
    }

    public function test_yields_nothing_when_the_project_has_no_composer_json(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures/does-not-exist'));

        self::assertSame([], $classes);
    }

    // --- A missing autoload.psr-4 entry warns via error_log() rather
    // than failing silently. ---

    public function test_warns_via_error_log_when_the_project_has_no_composer_json(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'kinetis_namespace_scanner_test_');
        $previous = ini_set('error_log', $logFile);

        try {
            iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures/does-not-exist'));

            $logged = (string) file_get_contents($logFile);
            self::assertStringContainsString('found no PSR-4 root to scan', $logged);
            self::assertStringContainsString('autoload', $logged);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($logFile);
        }
    }

    public function test_does_not_warn_when_a_real_psr4_root_is_configured(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'kinetis_namespace_scanner_test_');
        $previous = ini_set('error_log', $logFile);

        try {
            iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures'));

            self::assertSame('', file_get_contents($logFile));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($logFile);
        }
    }

    public function test_deduplicates_classes_found_through_overlapping_paths(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures', ['Console', 'Console']));

        self::assertSame(
            ['Kinetis\Tests\Cache\Fixtures\Console\DiscoveredPingCommand'],
            $classes,
        );
    }

    public function test_scans_a_fixed_segment_under_the_given_framework_root(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesUnderFrameworkSegment(
            'Console',
            __DIR__ . '/Fixtures',
        ));

        self::assertContains('Kinetis\Tests\Cache\Fixtures\Console\DiscoveredPingCommand', $classes);
    }

    public function test_a_framework_segment_scan_ignores_classes_outside_that_segment(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesUnderFrameworkSegment(
            'Console',
            __DIR__ . '/Fixtures',
        ));

        self::assertNotContains('Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalPingCommand', $classes);
    }

    public function test_the_real_kinetis_framework_root_is_scanned_by_default(): void
    {
        $classes = iterator_to_array(NamespaceScanner::classesUnderFrameworkSegment('Console'));

        self::assertContains('Kinetis\Console\BuildCommand', $classes);
        self::assertContains('Kinetis\Console\McpServeCommand', $classes);
    }

    public function test_does_not_yield_a_class_that_cannot_be_registered(): void
    {
        // Discovery walks whole directories, so an abstract base or an
        // enum under a scanned namespace has to be skipped rather than
        // fail the application — AttributeScope::reflect() is what fails
        // loudly when one is registered by name instead.
        $classes = iterator_to_array(NamespaceScanner::classesInProject(__DIR__ . '/Fixtures'));

        self::assertNotContains(AbstractScannedController::class, $classes);
        // The concrete controller in the same directory still is yielded,
        // so this is the abstract check firing and not an empty scan.
        self::assertContains('Kinetis\\Tests\\Cache\\Fixtures\\Http\\DiscoveredPingController', $classes);
    }
}
