<?php

declare(strict_types=1);

namespace Kinetis\Console;

/**
 * Injected into a command method by type — the console analogue of
 * ProgressReporter/ServerRequestInterface's own by-type special-casing.
 * parse() splits a command's own $argv slice into positional values and
 * `--key=value`/bare-`--flag` options — the syntax kinetis/queue's own
 * `queue:work --queue=high,default` flag uses. No space-separated
 * `--key value` form and no short flags (`-v`) — both are ambiguous or
 * unprecedented without a real need to justify the extra complexity.
 */
final readonly class CommandArguments
{
    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     */
    public function __construct(
        private array $positional,
        private array $options,
    ) {}

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): self
    {
        $positional = [];
        $options = [];

        foreach ($argv as $argument) {
            if (!str_starts_with($argument, '--')) {
                $positional[] = $argument;
                continue;
            }

            $body = substr($argument, 2);
            $separator = strpos($body, '=');

            if ($separator === false) {
                $options[$body] = true;
            } else {
                $options[substr($body, 0, $separator)] = substr($body, $separator + 1);
            }
        }

        return new self($positional, $options);
    }

    public function get(int $index): ?string
    {
        return $this->positional[$index] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->positional;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }
}
