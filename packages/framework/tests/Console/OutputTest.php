<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console;

use Kinetis\Console\Color;
use Kinetis\Console\Output;
use Kinetis\Tests\Console\Fixtures\ThrottledStream;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    private function capturing(bool $stdoutColors = false, bool $stderrColors = false): Output
    {
        return new Output($this->stdout, $this->stderr, $stdoutColors, $stderrColors);
    }

    /**
     * @param resource $stream
     */
    private static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_line_writes_to_stdout_and_error_to_stderr(): void
    {
        $output = $this->capturing();
        $output->line('result');
        $output->line();
        $output->error('problem');

        self::assertSame("result\n\n", self::contents($this->stdout));
        self::assertSame("problem\n", self::contents($this->stderr));
    }

    public function test_write_is_byte_exact_and_never_coloured(): void
    {
        $document = "{\"a\":\"\u{e9}\xff\"}";

        $this->capturing(stdoutColors: true)->write($document);

        self::assertSame($document, self::contents($this->stdout));
    }

    public function test_colour_is_applied_only_on_a_stream_with_colours_enabled(): void
    {
        $output = $this->capturing(stdoutColors: true, stderrColors: false);
        $output->line('ok', Color::Green);
        $output->line('plain');
        $output->error('failed', Color::Red);

        self::assertSame("\e[32mok\e[0m\nplain\n", self::contents($this->stdout));
        self::assertSame("failed\n", self::contents($this->stderr));
    }

    public function test_each_colour_maps_to_its_sgr_code(): void
    {
        $output = $this->capturing(stderrColors: true);

        foreach (Color::cases() as $color) {
            $output->error($color->name, $color);
        }

        self::assertSame(
            "\e[32mGreen\e[0m\n\e[33mYellow\e[0m\n\e[31mRed\e[0m\n\e[36mCyan\e[0m\n\e[90mGray\e[0m\n",
            self::contents($this->stderr),
        );
    }

    public function test_error_is_not_coloured_unless_asked(): void
    {
        $this->capturing(stderrColors: true)->error('failed');

        self::assertSame("failed\n", self::contents($this->stderr));
    }

    public function test_colours_need_a_terminal_and_an_unset_or_empty_no_color(): void
    {
        self::assertTrue(Output::colorsEnabled(true, false));
        self::assertTrue(Output::colorsEnabled(true, ''));
        self::assertFalse(Output::colorsEnabled(true, '1'));
        self::assertFalse(Output::colorsEnabled(true, '0'));
        self::assertFalse(Output::colorsEnabled(false, false));
        self::assertFalse(Output::colorsEnabled(false, ''));
    }

    public function test_for_streams_does_not_colour_a_stream_that_is_not_a_terminal(): void
    {
        $previous = getenv('NO_COLOR');
        putenv('NO_COLOR');

        try {
            $output = Output::forStreams($this->stdout, $this->stderr);
            $output->line('ok', Color::Green);
            $output->error('failed', Color::Red);
        } finally {
            putenv($previous === false ? 'NO_COLOR' : "NO_COLOR={$previous}");
        }

        self::assertSame("ok\n", self::contents($this->stdout));
        self::assertSame("failed\n", self::contents($this->stderr));
    }

    public function test_table_pads_columns_by_character_and_trims_trailing_space(): void
    {
        $this->capturing()->table(
            ['Name', 'Qty', 'Note'],
            [
                ['Café', 3, null],
                ['Tea', 12.5, 'hot'],
            ],
        );

        self::assertSame(
            "Name  Qty   Note\n"
            . "----  ----  ----\n"
            . "Café  3\n"
            . "Tea   12.5  hot\n",
            self::contents($this->stdout),
        );
    }

    public function test_table_pads_text_that_is_not_utf8_by_byte(): void
    {
        $this->capturing()->table(['A', 'B'], [["\u{e9}\xff", 'x']]);

        // Three bytes, so three columns wide.
        self::assertSame("A    B\n---  -\n\u{e9}\xff  x\n", self::contents($this->stdout));
    }

    public function test_a_table_without_rows_prints_its_header_and_rule(): void
    {
        $this->capturing()->table(['Name', 'Qty'], []);

        self::assertSame("Name  Qty\n----  ---\n", self::contents($this->stdout));
    }

    public function test_a_partial_write_is_completed(): void
    {
        $stream = ThrottledStream::open(acceptPerCall: 3);
        $text = str_repeat('0123456789', 5);

        new Output($stream, $this->stderr, false, false)->line($text);

        self::assertSame($text . "\n", ThrottledStream::$written);
    }

    public function test_a_stream_that_accepts_nothing_ends_the_write(): void
    {
        $stream = ThrottledStream::open(acceptPerCall: 0);

        new Output($stream, $this->stderr, false, false)->line('lost');

        self::assertSame('', ThrottledStream::$written);
    }

    public function test_the_streams_stay_open(): void
    {
        $output = $this->capturing();
        $output->line('x');
        unset($output);

        self::assertTrue(is_resource($this->stdout));
        self::assertTrue(is_resource($this->stderr));
    }
}
