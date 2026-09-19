<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use Composer\InstalledVersions;
use RuntimeException;

/**
 * The installed `kinetis/*` packages the Orbitron commands report, read
 * once per instance from the records handed to the constructor.
 *
 * This is the seam the suite constructs directly: passing a list of
 * PackageFact objects exercises the same filtering, ordering and
 * de-duplication production runs on, without touching Composer's
 * process-global installed state. Passing null — what the container's
 * autowiring does when it builds a command — reads that state instead.
 * Nothing is memoized across instances, so a second construction with
 * different records reports the different records.
 */
final readonly class InstalledPackages
{
    private const string PREFIX = 'kinetis/';
    private const string ORBITRON = 'kinetis/orbitron';

    /** @var array<string, string> package name => pretty version, ordered by name */
    private array $versions;

    /** @var array<string, true> the names above Composer reports as the root project */
    private array $roots;

    /**
     * Where a real installed dependency lives and at what version — the
     * one place an install path is retained for use rather than only as
     * a presence test, and the lookup {@see source()} answers from.
     *
     * @var array<string, array{version: string, root: string}>
     */
    private array $sources;

    /**
     * @param list<PackageFact>|null $facts the records to read, or null to
     *        read Composer's own installed set — the production path.
     */
    public function __construct(?array $facts = null)
    {
        $versions = [];
        $roots = [];
        $paths = [];

        foreach ($facts ?? self::readComposer() as $fact) {
            // A name that is only replaced or provided carries neither a
            // version nor an install path. Both are required here: either
            // one alone would let such a name through as a package that
            // is not installed at all. `??=` keeps the first record for a
            // name, matching Composer's own first-match lookup.
            if (!str_starts_with($fact->name, self::PREFIX)
                || $fact->version === null
                || $fact->installPath === null) {
                continue;
            }

            $versions[$fact->name] ??= $fact->version;
            $paths[$fact->name] ??= $fact->installPath;

            if ($fact->root) {
                $roots[$fact->name] = true;
            }
        }

        ksort($versions, SORT_STRING);

        $sources = [];

        foreach ($versions as $name => $version) {
            // The root project is the checkout being developed, not
            // something this project installed, so it is not a package
            // whose installed source can be read.
            if (!isset($roots[$name])) {
                $sources[$name] = ['version' => $version, 'root' => $paths[$name]];
            }
        }

        $this->versions = $versions;
        $this->roots = $roots;
        $this->sources = $sources;
    }

    /**
     * The version and install root of one real installed, non-root
     * `kinetis/*` package, or null for every other name — including a
     * name that is only replaced or provided, and the root project
     * itself.
     *
     * This is the only accessor that hands out an install path, and it
     * hands it to the source reader alone. No document built from this
     * object carries one: {@see records()} reports names and versions,
     * and the reader reports neither the root it resolved nor the path
     * it opened.
     *
     * @return array{version: string, root: string}|null
     */
    public function source(string $name): ?array
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * The installed packages as the `{name, version}` records the
     * context and inventory documents carry, in name order.
     *
     * The Composer root project is left out: it is the project being
     * developed, not something this project installed, so reporting it
     * as a dependency at a version would be untrue. It is still retained
     * above, because {@see orbitronVersion()} needs it when Orbitron
     * itself is the root. Install paths never reach these records;
     * {@see source()} is the one place they leave this object.
     *
     * @return list<array{name: string, version: string}>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->versions as $name => $version) {
            if (isset($this->roots[$name])) {
                continue;
            }

            $records[] = ['name' => $name, 'version' => $version];
        }

        return $records;
    }

    /**
     * Orbitron's own version, which is the single authority every
     * document reports — the detected package fact, never a constant
     * maintained beside it. It answers from the retained set, so a
     * checkout of this package developing itself still names its
     * version.
     *
     * @throws RuntimeException when the records carry no entry for this
     *         package, the one state in which Orbitron cannot name its
     *         own version.
     */
    public function orbitronVersion(): string
    {
        return $this->versions[self::ORBITRON] ?? throw new RuntimeException(
            'No installed ' . self::ORBITRON . ' package was found, so Orbitron cannot state its own version.',
        );
    }

    /**
     * Composer lists the root project among the installed packages and
     * names it in one other place; both are read here so the records
     * downstream can tell the two apart.
     *
     * @return list<PackageFact>
     */
    private static function readComposer(): array
    {
        $root = InstalledVersions::getRootPackage()['name'];
        $facts = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $facts[] = new PackageFact(
                $name,
                InstalledVersions::getPrettyVersion($name),
                InstalledVersions::getInstallPath($name),
                $name === $root,
            );
        }

        return $facts;
    }
}
