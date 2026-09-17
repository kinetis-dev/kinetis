<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use JsonException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\InstalledPackages;

/**
 * `orbitron:inspect` — the installed `kinetis/*` inventory as one JSON
 * document on STDOUT, for an agent that reads a project's Kinetis
 * versions before reading a guide.
 *
 * JSON is the only format: this document exists to be parsed. Omitting
 * `--format` and writing `--format=json` are the same invocation.
 *
 * `bootstrap: false`, for the same reason as `orbitron:context`.
 */
final readonly class InspectCommand
{
    /** The envelope's own version, moved only when the document's shape changes. */
    public const int SCHEMA_VERSION = 1;

    /** @var non-empty-list<string> */
    private const array FORMATS = ['json'];

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private InstalledPackages $packages = new InstalledPackages(),
        private mixed $output = STDOUT,
        private mixed $errorOutput = STDERR,
    ) {}

    /**
     * @throws JsonException
     */
    #[Command(
        'orbitron:inspect',
        description: 'Prints the installed kinetis/* packages and their versions as JSON',
        bootstrap: false,
    )]
    public function run(CommandArguments $arguments): int
    {
        if (Invocation::format($arguments, self::FORMATS) === null) {
            fwrite(
                $this->errorOutput,
                "orbitron:inspect takes no arguments and accepts --format=json only.\n",
            );

            return 2;
        }

        fwrite($this->output, JsonDocument::render([
            'schemaVersion' => self::SCHEMA_VERSION,
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'packages' => $this->packages->records(),
        ]));

        return 0;
    }
}
