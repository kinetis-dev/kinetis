<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One window of one file of one installed `kinetis/*` package, as the
 * JSON document the MCP tool returns.
 *
 * The documentation pages Orbitron serves are published from main and
 * can describe behavior newer than a project has installed. This reader
 * is the authority for what the project actually runs: the source on
 * disk, at the version {@see InstalledPackages} reports, read live on
 * every call.
 *
 * Nothing here is chosen by a caller except the package name, the
 * relative path, and the window. The install root comes from Composer's
 * own installed set; the path is admitted against a fixed set of
 * locations before anything is opened; and the resolved target must be a
 * regular file that still sits in the admitted location the request
 * named. A symlink is therefore resolved and then re-admitted, so one
 * pointing out of the package, or at a part of it this tool does not
 * serve, is refused rather than followed. A refusal names a fixed code
 * and nothing else — no resolved path, no exception text, no content.
 *
 * The read is bounded by construction: one stream read of the admitted
 * size plus one byte, and that byte alone tells an admitted file from an
 * oversized one. Nothing is retained between calls.
 */
final readonly class PackageSourceReader
{
    /**
     * The largest file that is read. One byte beyond it is requested as
     * well, and the oversized case is never read whole.
     */
    public const int MAX_SOURCE_BYTES = 1048576;

    /** The most lines one call returns, which is also the default. */
    public const int MAX_LINE_COUNT = 200;

    /** The longest path a call may name, counted in Unicode characters. */
    public const int MAX_PATH_LENGTH = 256;

    /** The two package-root files a call may name. */
    private const array ROOT_FILES = ['composer.json', 'README.md'];

    /** The three directories a call may read beneath. */
    private const array DIRECTORIES = ['src', 'bin', 'resources'];

    public function __construct(private InstalledPackages $packages = new InstalledPackages()) {}

    /**
     * The window, or the refusal. Both are documents; the refusal is the
     * failed one.
     *
     * @param string $path relative `/` syntax, admitted below before any
     *        lookup result is turned into a filesystem operation
     * @param int $startLine one-based, already validated by the adapter
     * @param int $lineCount already validated by the adapter to 1..{@see MAX_LINE_COUNT}
     */
    public function read(string $package, string $path, int $startLine, int $lineCount): Document
    {
        $source = $this->packages->source($package);

        if ($source === null) {
            return self::refuse('package_unknown');
        }

        $location = self::location($path);

        if ($location === null) {
            return self::refuse('path_not_admitted');
        }

        $root = realpath($source['root']);
        $target = $root === false ? false : realpath($root . '/' . $path);

        if ($target === false) {
            return self::refuse('source_missing');
        }

        // The separator keeps a sibling directory whose name merely
        // starts with the root's out, and rejects a target that resolved
        // anywhere else. A directory or any other non-regular target is
        // refused here rather than opened.
        if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return self::refuse('source_unreadable');
        }

        // Admitting the path the caller wrote only bounds where the
        // request pointed. A symlink moves where it landed, so the
        // resolved file is admitted again and must sit in the same
        // location: `src/Link.php` reaching the README, the test suite
        // or the vendor tree is a read of something this tool does not
        // serve, however admitted its own name was.
        $resolved = str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($root) + 1));

        if (self::location($resolved) !== $location) {
            return self::refuse('path_not_admitted');
        }

        // Suppressed because the diagnostic is the returned code, not a
        // PHP warning on a stdout that carries JSON-RPC frames.
        $handle = @fopen($target, 'rb');

        if ($handle === false) {
            return self::refuse('source_unreadable');
        }

        $contents = stream_get_contents($handle, self::MAX_SOURCE_BYTES + 1);

        fclose($handle);

        if ($contents === false) {
            return self::refuse('source_unreadable');
        }

        // The extra byte arrived, so the file is larger than the
        // admitted size. Nothing beyond it was ever read.
        if (strlen($contents) > self::MAX_SOURCE_BYTES) {
            return self::refuse('source_oversize');
        }

        // A NUL byte or an invalid encoding means this is not the source
        // text the tool reports; `//u` decides UTF-8 without mbstring.
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            return self::refuse('source_not_text');
        }

        // Split after each newline, so every line keeps its own ending,
        // CRLF included, and a file with no final newline keeps that.
        // A split point only ever follows a newline, so the sole empty
        // piece PREG_SPLIT_NO_EMPTY can drop is the one past a trailing
        // newline.
        $lines = preg_split('/(?<=\n)/', $contents, flags: PREG_SPLIT_NO_EMPTY);
        \assert(is_array($lines));
        $total = count($lines);

        if ($startLine > $total) {
            return self::refuse('line_out_of_range');
        }

        $window = array_slice($lines, $startLine - 1, $lineCount);
        $endLine = $startLine + count($window) - 1;

        return new Document([
            'status' => 'ok',
            'package' => $package,
            'version' => $source['version'],
            'path' => $path,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'hasMore' => $endLine < $total,
            'content' => implode('', $window),
        ], failed: false);
    }

    /**
     * The admitted location the path names — the root file itself, or
     * the directory it lies beneath — or null when the path is outside
     * the syntax or the locations this tool admits.
     *
     * An empty segment covers a leading or trailing slash and a doubled
     * one, so no separator form reaches the filesystem; `.` and `..` are
     * refused outright rather than resolved and then checked.
     */
    private static function location(string $path): ?string
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        if (count($segments) === 1) {
            return in_array($path, self::ROOT_FILES, true) ? $path : null;
        }

        return in_array($segments[0], self::DIRECTORIES, true) ? $segments[0] : null;
    }

    /**
     * A refusal carries the code and nothing else: a path, a root, a
     * Composer detail or a fragment of the file would each be a leak out
     * of a tool whose whole read set is meant to be unobservable.
     */
    private static function refuse(string $code): Document
    {
        return new Document(['status' => 'error', 'code' => $code], failed: true);
    }
}
