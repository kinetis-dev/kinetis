<?php

declare(strict_types=1);

/**
 * The one version-transition policy every tool in this directory
 * shares: generate-composer.php's --bump/--set-version and
 * validate-manifest.php's version-bump check both decide what a legal
 * version move is here and nowhere else, so a bump the generator writes
 * is exactly a bump the validator accepts.
 *
 * Kinetis stays on the 1.x line through incubation (see
 * docs/appendix-contributing.md). Within that line a package moves one
 * step at a time: 1.m.p -> 1.m.(p+1) for a fix or maintenance pass, or
 * 1.m.p -> 1.(m+1).0 for a new capability or a breaking change. A new
 * package starts at 1.0.0.
 *
 * One step at a time is what keeps every version reachable: release.yml
 * tags whatever version lands on main, so jumping 1.2.3 -> 1.2.5 leaves
 * 1.2.4 permanently untagged while its content ships inside 1.2.5's tag.
 * A multi-commit push is compared end to end, so an intermediate commit
 * cannot launder a skipped version either.
 */

/** Every package stays on this major line for the duration of incubation. */
const INCUBATION_MAJOR = 1;

/** The bump sizes the policy offers. Ordered smallest first. */
const BUMP_COMPONENTS = ['patch', 'minor'];

/**
 * The one parser. Every version this tooling reads — the manifest on
 * disk, a manifest at a past commit, a --bump target, a --set-version
 * argument — comes through here, so all of them agree on what a version
 * even is.
 *
 * A component is canonical decimal, at most nine digits. `01` and
 * `1.0.00` are rejected rather than silently meaning the same thing as
 * their canonical spelling, because the manifest value is compared as a
 * string in several places and a tag is published from it verbatim. The
 * width cap keeps every component well inside the int range, so the
 * arithmetic below has no overflow case to model.
 *
 * @return array{major: int, minor: int, patch: int}|null
 */
function parseVersion(string $version): ?array
{
    $component = '(0|[1-9][0-9]{0,8})';

    if (preg_match("/^{$component}\\.{$component}\\.{$component}$/", $version, $m) !== 1) {
        return null;
    }

    return ['major' => (int) $m[1], 'minor' => (int) $m[2], 'patch' => (int) $m[3]];
}

/** The version a brand-new package starts its own line at. */
function initialVersion(): string
{
    return INCUBATION_MAJOR . '.0.0';
}

/**
 * The single step $component takes from $current.
 *
 * @throws InvalidArgumentException when $current doesn't parse or
 *         $component isn't a bump size
 */
function nextVersion(string $current, string $component): string
{
    $v = parseVersion($current);

    if ($v === null) {
        throw new InvalidArgumentException("Not a canonical X.Y.Z version: {$current}");
    }

    if (!in_array($component, BUMP_COMPONENTS, true)) {
        throw new InvalidArgumentException("Unknown bump component: {$component}");
    }

    return match ($component) {
        'patch' => "{$v['major']}.{$v['minor']}." . ($v['patch'] + 1),
        'minor' => "{$v['major']}." . ($v['minor'] + 1) . '.0',
    };
}

/**
 * Every version $current is allowed to move to, in bump-size order.
 * Drives both the generator's own arithmetic and the wording of a
 * rejection, so the two describe one rule. Empty when $current doesn't
 * parse.
 *
 * @return list<string>
 */
function allowedNextVersions(string $current): array
{
    if (parseVersion($current) === null) {
        return [];
    }

    return array_map(static fn (string $component): string => nextVersion($current, $component), BUMP_COMPONENTS);
}

/**
 * The policy itself. $old is null for a package with no prior entry —
 * one being added in this same change.
 *
 * @return string|null a description of why the move is rejected, or
 *         null when it's allowed
 */
function versionTransitionProblem(?string $old, string $new): ?string
{
    $newParts = parseVersion($new);

    if ($newParts === null) {
        return "version '{$new}' is not a canonical X.Y.Z version";
    }

    if ($newParts['major'] !== INCUBATION_MAJOR) {
        return "version '{$new}' leaves the " . INCUBATION_MAJOR . '.x line — '
            . 'a breaking change ships as a minor bump through incubation';
    }

    if ($old === null) {
        return $new === initialVersion()
            ? null
            : 'a new package starts at ' . initialVersion() . ", not '{$new}'";
    }

    $oldParts = parseVersion($old);

    if ($oldParts === null) {
        return "previous version '{$old}' is not a canonical X.Y.Z version";
    }

    if ($oldParts['major'] !== INCUBATION_MAJOR) {
        return "previous version '{$old}' is not on the " . INCUBATION_MAJOR . '.x line';
    }

    $allowed = allowedNextVersions($old);

    if (in_array($new, $allowed, true)) {
        return null;
    }

    if ($new === $old) {
        return "version '{$new}' is unchanged — a release needs a new version";
    }

    if (array_values($newParts) < array_values($oldParts)) {
        return "version {$new} is lower than {$old}";
    }

    return "version jumped from {$old} to {$new} — the only steps allowed are "
        . implode(' or ', $allowed) . ', so every version in between stays reachable';
}
