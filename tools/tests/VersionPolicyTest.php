<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../generate-composer.php';

final class VersionPolicyTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function acceptedTransitions(): iterable
    {
        yield 'patch step' => ['1.4.2', '1.4.3'];
        yield 'patch step from zero' => ['1.0.0', '1.0.1'];
        yield 'minor step resets the patch' => ['1.4.2', '1.5.0'];
        yield 'minor step from a zero patch' => ['1.4.0', '1.5.0'];
        yield 'patch across a two-digit minor' => ['1.12.9', '1.12.10'];
        yield 'a new package starts at 1.0.0' => [null, '1.0.0'];
    }

    #[DataProvider('acceptedTransitions')]
    public function test_an_allowed_transition_reports_no_problem(?string $old, string $new): void
    {
        self::assertNull(versionTransitionProblem($old, $new));
    }

    /** @return iterable<string, array{?string, string, string}> */
    public static function rejectedTransitions(): iterable
    {
        yield 'major bump' => ['1.4.2', '2.0.0', 'leaves the 1.x line'];
        yield 'major bump from a new package' => [null, '2.0.0', 'leaves the 1.x line'];
        yield 'skipped patch' => ['1.2.3', '1.2.5', 'jumped from 1.2.3 to 1.2.5'];
        yield 'skipped minor' => ['1.2.3', '1.4.0', 'jumped from 1.2.3 to 1.4.0'];
        yield 'minor step keeping a nonzero patch' => ['1.2.3', '1.3.3', 'jumped from 1.2.3 to 1.3.3'];
        yield 'minor step inventing a patch' => ['1.2.3', '1.3.1', 'jumped from 1.2.3 to 1.3.1'];
        yield 'patch downgrade' => ['1.2.3', '1.2.2', 'is lower than'];
        yield 'minor downgrade' => ['1.2.3', '1.1.0', 'is lower than'];
        yield 'no-op' => ['1.2.3', '1.2.3', 'is unchanged'];
        yield 'not semver' => ['1.2.3', '1.3', 'not a canonical X.Y.Z version'];
        yield 'prerelease suffix' => ['1.2.3', '1.2.4-rc1', 'not a canonical X.Y.Z version'];
        yield 'a leading zero patch' => ['1.2.3', '1.2.04', 'not a canonical X.Y.Z version'];
        yield 'a leading zero minor' => ['1.2.3', '1.03.0', 'not a canonical X.Y.Z version'];
        yield 'a leading zero major' => ['1.2.3', '01.2.4', 'not a canonical X.Y.Z version'];
        yield 'a patch one beyond the int range' => ['1.2.3', '1.2.' . self::oneBeyondIntMax(), 'not a canonical X.Y.Z version'];
        yield 'a new package starting anywhere else' => [null, '1.0.1', 'a new package starts at 1.0.0'];
        yield 'a new package starting on a later minor' => [null, '1.1.0', 'a new package starts at 1.0.0'];
        yield 'a prior version off the 1.x line' => ['0.9.0', '1.0.0', "previous version '0.9.0' is not on the 1.x line"];
        yield 'a malformed prior version' => ['1.2', '1.2.1', "previous version '1.2' is not a canonical X.Y.Z version"];
        yield 'a prior version with a leading zero' => ['1.02.3', '1.2.4', "previous version '1.02.3' is not a canonical"];
        yield 'a prior patch one beyond the int range' => ['1.2.' . self::oneBeyondIntMax(), '1.2.4', 'is not a canonical'];
    }

    #[DataProvider('rejectedTransitions')]
    public function test_a_rejected_transition_says_why(?string $old, string $new, string $expected): void
    {
        $problem = versionTransitionProblem($old, $new);

        self::assertNotNull($problem);
        self::assertStringContainsString($expected, $problem);
    }

    public function test_the_two_allowed_next_versions_are_the_patch_and_the_minor_step(): void
    {
        self::assertSame(['1.4.3', '1.5.0'], allowedNextVersions('1.4.2'));
    }

    public function test_every_allowed_next_version_is_one_the_policy_accepts(): void
    {
        foreach (allowedNextVersions('1.4.2') as $next) {
            self::assertNull(versionTransitionProblem('1.4.2', $next), "{$next} must be reachable from 1.4.2");
        }
    }

    public function test_next_version_patch_increments_the_patch_only(): void
    {
        self::assertSame('1.4.3', nextVersion('1.4.2', 'patch'));
    }

    public function test_next_version_minor_resets_the_patch(): void
    {
        self::assertSame('1.5.0', nextVersion('1.4.2', 'minor'));
    }

    public function test_next_version_rejects_a_component_the_policy_does_not_offer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        nextVersion('1.4.2', 'major');
    }

    /**
     * The largest component the tool can hold. Parsing it is fine —
     * refusing to read a version that is already in the manifest would
     * help nobody — but stepping from it is the operation that has
     * nowhere to go.
     */
    public function test_a_component_at_the_int_maximum_parses(): void
    {
        $version = '1.0.' . PHP_INT_MAX;

        self::assertSame(['major' => 1, 'minor' => 0, 'patch' => PHP_INT_MAX], parseVersion($version));
    }

    public function test_a_component_one_beyond_the_int_maximum_does_not_parse(): void
    {
        // Cast, it would saturate at PHP_INT_MAX and compare equal to the
        // version below it, so two different strings would pass and fail
        // the same checks inconsistently.
        self::assertNull(parseVersion('1.0.' . self::oneBeyondIntMax()));
        self::assertNull(parseVersion(self::oneBeyondIntMax() . '.0.0'));
        self::assertNull(parseVersion('1.' . self::oneBeyondIntMax() . '.0'));
    }

    public function test_a_step_that_would_overflow_is_refused_rather_than_wrapping(): void
    {
        $this->expectException(InvalidArgumentException::class);

        nextVersion('1.0.' . PHP_INT_MAX, 'patch');
    }

    public function test_a_step_the_other_component_can_still_take_is_offered(): void
    {
        $version = '1.0.' . PHP_INT_MAX;

        self::assertFalse(canStep($version, 'patch'));
        self::assertTrue(canStep($version, 'minor'));
        self::assertSame(['1.1.0'], allowedNextVersions($version));
        self::assertNull(versionTransitionProblem($version, '1.1.0'));
    }

    public function test_a_version_with_no_step_left_at_all_is_rejected_rather_than_overflowing(): void
    {
        $version = '1.' . PHP_INT_MAX . '.' . PHP_INT_MAX;

        self::assertSame([], allowedNextVersions($version));
        $problem = versionTransitionProblem($version, '1.0.0');
        self::assertNotNull($problem);
        self::assertStringContainsString('no step from version', $problem);
    }

    /** @return iterable<string, array{string}> */
    public static function noncanonicalVersions(): iterable
    {
        yield 'a leading zero patch' => ['1.0.01'];
        yield 'a leading zero minor' => ['1.00.0'];
        yield 'a leading zero major' => ['01.0.0'];
        yield 'all zeros padded' => ['0.0.00'];
        yield 'a plus-signed component' => ['1.0.+1'];
        yield 'a spaced component' => ['1.0. 1'];
    }

    #[DataProvider('noncanonicalVersions')]
    public function test_a_noncanonical_component_does_not_parse(string $version): void
    {
        self::assertNull(parseVersion($version));
    }

    public function test_a_plain_zero_component_is_canonical(): void
    {
        self::assertSame(['major' => 0, 'minor' => 0, 'patch' => 0], parseVersion('0.0.0'));
    }

    private static function oneBeyondIntMax(): string
    {
        // PHP_INT_MAX + 1 as digits, without ever holding it as an int.
        $digits = (string) PHP_INT_MAX;
        $carry = 1;

        for ($i = strlen($digits) - 1; $i >= 0 && $carry === 1; $i--) {
            $sum = (int) $digits[$i] + $carry;
            $digits[$i] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        return $carry === 1 ? '1' . $digits : $digits;
    }

    public function test_parse_version_reads_a_plain_x_y_z(): void
    {
        self::assertSame(['major' => 1, 'minor' => 4, 'patch' => 2], parseVersion('1.4.2'));
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableVersions(): iterable
    {
        yield 'two parts' => ['1.4'];
        yield 'four parts' => ['1.4.2.1'];
        yield 'prerelease' => ['1.4.2-rc1'];
        yield 'build metadata' => ['1.4.2+build'];
        yield 'leading v' => ['v1.4.2'];
        yield 'empty' => [''];
        yield 'negative' => ['-1.4.2'];
    }

    #[DataProvider('unparseableVersions')]
    public function test_parse_version_reports_null_for_anything_else(string $version): void
    {
        self::assertNull(parseVersion($version));
    }

    /**
     * The multi-commit push this rule exists for: a branch that bumps
     * 1.2.3 -> 1.2.4 in one commit and 1.2.4 -> 1.2.5 in the next reads,
     * end to end, as 1.2.3 -> 1.2.5. release.yml tags only what lands on
     * main, so accepting that leaves 1.2.4 permanently untagged with its
     * content folded into 1.2.5's tag.
     */
    public function test_two_bumps_in_one_push_are_rejected_end_to_end_though_each_step_is_legal(): void
    {
        self::assertNull(versionTransitionProblem('1.2.3', '1.2.4'));
        self::assertNull(versionTransitionProblem('1.2.4', '1.2.5'));
        self::assertNotNull(versionTransitionProblem('1.2.3', '1.2.5'));
    }

    /**
     * The policy is stated on the net move, which is what release.yml
     * tags. A patch and then a minor within one push nets out to the
     * minor, so it is one release and passes; the patch never reached
     * main and was never a release to skip.
     */
    public function test_a_patch_then_a_minor_in_one_push_nets_out_to_the_minor_it_ships(): void
    {
        self::assertNull(versionTransitionProblem('1.2.3', '1.2.4'));
        self::assertNull(versionTransitionProblem('1.2.4', '1.3.0'));
        self::assertNull(versionTransitionProblem('1.2.3', '1.3.0'));
    }

    /**
     * A minor and then a patch does not net out to anything reachable:
     * 1.3.0 would have had to be tagged for 1.3.1 to follow it.
     */
    public function test_a_minor_then_a_patch_in_one_push_is_rejected_end_to_end(): void
    {
        self::assertNull(versionTransitionProblem('1.2.3', '1.3.0'));
        self::assertNull(versionTransitionProblem('1.3.0', '1.3.1'));
        self::assertNotNull(versionTransitionProblem('1.2.3', '1.3.1'));
    }
}
