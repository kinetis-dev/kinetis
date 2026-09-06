<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../version-policy.php';

final class VersionPolicyTest extends TestCase
{
    #[DataProvider('canonicalVersions')]
    public function test_a_canonical_version_parses(string $version, int $major, int $minor, int $patch): void
    {
        self::assertSame(['major' => $major, 'minor' => $minor, 'patch' => $patch], parseVersion($version));
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function canonicalVersions(): iterable
    {
        yield 'zero' => ['0.0.0', 0, 0, 0];
        yield 'initial' => ['1.0.0', 1, 0, 0];
        yield 'multi-digit' => ['1.12.30', 1, 12, 30];
    }

    #[DataProvider('rejectedVersions')]
    public function test_a_non_canonical_version_is_rejected(string $version): void
    {
        self::assertNull(parseVersion($version));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedVersions(): iterable
    {
        yield 'leading zero' => ['1.01.0'];
        yield 'two components' => ['1.0'];
        yield 'four components' => ['1.0.0.0'];
        yield 'prerelease' => ['1.0.0-beta'];
        yield 'v prefix' => ['v1.0.0'];
        yield 'blank' => [''];
        yield 'wider than the component cap' => ['1.0.1234567890'];
    }

    public function test_a_patch_step_moves_the_last_component(): void
    {
        self::assertSame('1.4.3', nextVersion('1.4.2', 'patch'));
    }

    public function test_a_minor_step_resets_the_patch(): void
    {
        self::assertSame('1.5.0', nextVersion('1.4.2', 'minor'));
    }

    public function test_the_allowed_moves_are_the_patch_and_the_minor_in_that_order(): void
    {
        self::assertSame(['1.4.3', '1.5.0'], allowedNextVersions('1.4.2'));
    }

    public function test_an_unparseable_current_version_allows_no_move(): void
    {
        self::assertSame([], allowedNextVersions('nonsense'));
    }

    public function test_a_new_package_starts_at_the_initial_version(): void
    {
        self::assertNull(versionTransitionProblem(null, '1.0.0'));
    }

    public function test_a_new_package_cannot_start_anywhere_else(): void
    {
        self::assertStringContainsString('starts at 1.0.0', (string) versionTransitionProblem(null, '1.2.0'));
    }

    #[DataProvider('allowedTransitions')]
    public function test_a_one_step_move_is_allowed(string $old, string $new): void
    {
        self::assertNull(versionTransitionProblem($old, $new));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedTransitions(): iterable
    {
        yield 'patch' => ['1.4.2', '1.4.3'];
        yield 'minor' => ['1.4.2', '1.5.0'];
    }

    public function test_a_major_bump_leaves_the_incubation_line(): void
    {
        self::assertStringContainsString('leaves the 1.x line', (string) versionTransitionProblem('1.4.2', '2.0.0'));
    }

    public function test_a_skipped_patch_names_every_version_it_would_strand(): void
    {
        $problem = (string) versionTransitionProblem('1.4.2', '1.4.4');

        self::assertStringContainsString('1.4.3 or 1.5.0', $problem);
        self::assertStringContainsString('stays reachable', $problem);
    }

    public function test_a_skipped_minor_is_rejected(): void
    {
        self::assertStringContainsString('jumped from 1.4.2 to 1.6.0', (string) versionTransitionProblem('1.4.2', '1.6.0'));
    }

    public function test_two_bumps_in_one_change_are_rejected_as_a_jump(): void
    {
        self::assertStringContainsString('jumped from 1.4.2 to 1.4.4', (string) versionTransitionProblem('1.4.2', '1.4.4'));
    }

    public function test_an_unchanged_version_is_not_a_release(): void
    {
        self::assertStringContainsString('is unchanged', (string) versionTransitionProblem('1.4.2', '1.4.2'));
    }

    public function test_going_backwards_is_named_as_such(): void
    {
        self::assertStringContainsString('lower than', (string) versionTransitionProblem('1.4.2', '1.4.1'));
    }

    public function test_a_non_canonical_target_is_rejected_before_anything_else(): void
    {
        self::assertStringContainsString('not a canonical', (string) versionTransitionProblem('1.4.2', '1.4.3-rc1'));
    }

    public function test_a_previous_version_off_the_incubation_line_is_rejected(): void
    {
        self::assertStringContainsString('not on the 1.x line', (string) versionTransitionProblem('0.9.0', '1.0.0'));
    }
}
