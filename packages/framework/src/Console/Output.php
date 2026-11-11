<?php

declare(strict_types=1);

namespace Kinetis\Console;

/**
 * A command's two output streams: results on stdout, diagnostics on
 * stderr. Constructor-injected into a #[Command] class; the command
 * runner binds one on the request scope it creates for each command.
 *
 * Request-neutral: two stream handles and one colour decision per
 * stream. It never closes either stream — the process, or the test that
 * opened them, owns that.
 *
 * Colour is applied only when the caller passes a {@see Color} and only
 * to a stream that was opened with colours enabled, so escape codes
 * never reach a pipe, a file, or a log collector. write() is never
 * coloured: it carries exact bytes, such as a JSON document.
 */
final readonly class Output
{
    /**
     * No stream defaults: an Output must come from the runner's binding
     * (or a test that owns its streams), never from autowiring that
     * silently falls back to the process streams.
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private mixed $stdout,
        private mixed $stderr,
        private bool $stdoutColors,
        private bool $stderrColors,
    ) {}

    /**
     * Enables colour on each stream independently, when that stream is a
     * terminal and `NO_COLOR` is unset or empty (https://no-color.org).
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    public static function forStreams(mixed $stdout, mixed $stderr): self
    {
        $noColor = getenv('NO_COLOR');

        return new self(
            $stdout,
            $stderr,
            self::colorsEnabled(stream_isatty($stdout), $noColor),
            self::colorsEnabled(stream_isatty($stderr), $noColor),
        );
    }

    /**
     * The decision forStreams() makes per stream, separated so it is
     * testable without a terminal.
     *
     * @internal
     */
    public static function colorsEnabled(bool $isTerminal, string|false $noColor): bool
    {
        return $isTerminal && ($noColor === false || $noColor === '');
    }

    /**
     * Writes one line to stdout.
     */
    public function line(string $text = '', ?Color $color = null): void
    {
        self::send($this->stdout, self::paint($text, $color, $this->stdoutColors) . "\n");
    }

    /**
     * Writes exactly these bytes to stdout: no newline, never coloured.
     */
    public function write(string $text): void
    {
        self::send($this->stdout, $text);
    }

    /**
     * Writes one line to stderr — usage errors, failures, anything that
     * is not the command's result.
     */
    public function error(string $text, ?Color $color = null): void
    {
        self::send($this->stderr, self::paint($text, $color, $this->stderrColors) . "\n");
    }

    /**
     * Writes a header row, a dashed rule, and the rows to stdout, each
     * column space-padded to its widest cell and separated by two
     * spaces. Width counts UTF-8 characters; text that is not valid
     * UTF-8 counts bytes.
     *
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $lines = [$headers];

        foreach ($rows as $row) {
            $lines[] = array_map(static fn (string|int|float|null $cell): string => (string) $cell, $row);
        }

        $widths = [];

        foreach ($lines as $cells) {
            foreach ($cells as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, self::width($cell));
            }
        }

        $rule = implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths));
        $text = self::row($headers, $widths) . $rule . "\n";

        foreach (array_slice($lines, 1) as $cells) {
            $text .= self::row($cells, $widths);
        }

        self::send($this->stdout, $text);
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $padded = [];

        foreach ($cells as $column => $cell) {
            $padded[] = $cell . str_repeat(' ', $widths[$column] - self::width($cell));
        }

        return rtrim(implode('  ', $padded), ' ') . "\n";
    }

    /**
     * Counts UTF-8 characters with PCRE rather than mbstring, which the
     * framework does not require.
     */
    private static function width(string $text): int
    {
        return preg_match_all('/./su', $text) ?: strlen($text);
    }

    private static function paint(string $text, ?Color $color, bool $enabled): string
    {
        if ($color === null || !$enabled) {
            return $text;
        }

        $code = match ($color) {
            Color::Green => '32',
            Color::Yellow => '33',
            Color::Red => '31',
            Color::Cyan => '36',
            Color::Gray => '90',
        };

        return "\e[{$code}m{$text}\e[0m";
    }

    /**
     * fwrite() may accept fewer bytes than offered (a pipe, a non-blocking
     * stream); the rest is offered again until the stream takes nothing,
     * which ends the write rather than spinning on a closed reader.
     *
     * @param resource $stream
     */
    private static function send(mixed $stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);

            if ($written === false || $written === 0) {
                return;
            }

            $bytes = substr($bytes, $written);
        }
    }
}
