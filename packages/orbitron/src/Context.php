<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * The Orbitron context document: what Orbitron is, what it does not do,
 * where the authoritative Kinetis guidance lives, the command workflow,
 * what each command may and may not change, and the installed
 * `kinetis/*` package facts.
 *
 * toArray() is the document; toMarkdown() renders that same array, so
 * the two output formats cannot state different things. The prose points
 * at the guides rather than reprinting them, and it claims nothing about
 * an application's correctness.
 */
final readonly class Context
{
    private const string DOCS = 'https://kinetis.dev/docs/';

    /** @var list<string> */
    private const array LIMITS = [
        'Orbitron ships no model, no MCP server, no HTTP client and no shell. It renders text and exits; you supply the coding agent.',
        'This document is reference material, not evidence. It does not establish that an application preserves request isolation, non-blocking I/O, or any other invariant — the guides below state the rules, and the project\'s own tests and review are what settle compliance.',
        'The package facts below describe what is installed in this project. They say nothing about the current state of Kinetis main.',
        'Orbitron is a require-dev package. No production code depends on it, and removing it changes nothing an application does.',
        'Orbitron reads Composer\'s installed-package metadata and, for `orbitron:verify`, the project\'s own `composer.json` through a bounded read — no other application source, no configuration and no credentials — and it writes no files.',
        '`orbitron:verify` answers one narrow question: whether this project\'s Composer layout is the fixed one Orbitron supports. That layout is narrower than anything Kinetis itself requires, so an error means the project is outside what Orbitron assumes — not that route, command or listener discovery is broken. It establishes nothing else either: not request isolation, not non-blocking I/O, not security, not route uniqueness, not the correctness of any application code.',
    ];

    /** @var list<array{title: string, url: string}> */
    private const array GUIDES = [
        ['title' => 'Agent Workflow', 'url' => self::DOCS . 'agent-workflow.html'],
        ['title' => 'Application Recipes', 'url' => self::DOCS . 'application-recipes.html'],
        ['title' => 'Agent Correctness Review', 'url' => self::DOCS . 'agent-correctness.html'],
        ['title' => 'Reference', 'url' => self::DOCS . 'reference.html'],
        ['title' => 'Orbitron', 'url' => self::DOCS . 'orbitron.html'],
    ];

    /** @var list<string> */
    private const array WORKFLOW = [
        'Run `vendor/bin/kinetis orbitron:context` once per task to read this document.',
        'Run `vendor/bin/kinetis orbitron:inspect` to read the installed Kinetis packages and their versions as JSON.',
        'Run `vendor/bin/kinetis orbitron:verify` to read whether this project\'s Composer layout is the one Orbitron supports; exit 3 means the document reports an error.',
        'Route the task through Agent Workflow, then follow the matching recipe — reading each guide for the versions orbitron:inspect reports, not for main.',
        'Before calling the change done, work through Agent Correctness Review and run the project\'s own test suite.',
    ];

    /** @var list<array{name: string, formats: list<string>, effect: string}> */
    private const array COMMANDS = [
        [
            'name' => 'orbitron:context',
            'formats' => ['markdown', 'json'],
            'effect' => 'Renders this document to STDOUT. Reads Composer\'s installed-package records and nothing else: writes no file, changes no cache, opens no socket, starts no process.',
        ],
        [
            'name' => 'orbitron:inspect',
            'formats' => ['json'],
            'effect' => 'Renders the installed kinetis/* inventory to STDOUT, under the same boundary: reads Composer\'s installed-package records and changes nothing.',
        ],
        [
            'name' => 'orbitron:verify',
            'formats' => ['json'],
            'effect' => 'Renders the project-layout verification to STDOUT. Reads Composer\'s installed-package records and the project\'s own composer.json through a bounded read, and changes nothing else: writes no file, opens no socket, starts no process. Exits 3 when the verification completed and the document reports an error.',
        ],
    ];

    private const string LAUNCHER = 'Orbitron changes nothing, but the invocation as a whole is not side-effect-free. '
        . '`vendor/bin/kinetis` loads `.env` before it dispatches any command, and under APP_ENV=production it compiles '
        . '`.kinetis-cache/compiled.php` when no valid artifact is present. Both belong to the framework launcher, and '
        . '`bootstrap: false` does not prevent either.';

    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * @return array{
     *     orbitronVersion: string,
     *     harness: array{name: string, role: string, limits: list<string>},
     *     guides: list<array{title: string, url: string}>,
     *     workflow: list<string>,
     *     commands: list<array{name: string, formats: list<string>, effect: string}>,
     *     launcher: string,
     *     packages: list<array{name: string, version: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'orbitronVersion' => $this->packages->orbitronVersion(),
            'harness' => [
                'name' => 'Orbitron',
                'role' => 'A development-only construction harness for Kinetis applications. It gives any shell-capable '
                    . 'coding agent portable Kinetis context and a stable installed-package inventory, with no MCP '
                    . 'configuration to set up.',
                'limits' => self::LIMITS,
            ],
            'guides' => self::GUIDES,
            'workflow' => self::WORKFLOW,
            'commands' => self::COMMANDS,
            'launcher' => self::LAUNCHER,
            'packages' => $this->packages->records(),
        ];
    }

    public function toMarkdown(): string
    {
        $document = $this->toArray();
        $harness = $document['harness'];

        $lines = [
            "# {$harness['name']} {$document['orbitronVersion']}",
            '',
            $harness['role'],
            '',
            '## Limits',
            '',
        ];

        foreach ($harness['limits'] as $limit) {
            $lines[] = "- {$limit}";
        }

        $lines[] = '';
        $lines[] = '## Authoritative guidance';
        $lines[] = '';

        foreach ($document['guides'] as $guide) {
            $lines[] = "- [{$guide['title']}]({$guide['url']})";
        }

        $lines[] = '';
        $lines[] = '## Workflow';
        $lines[] = '';

        foreach ($document['workflow'] as $index => $step) {
            $lines[] = ($index + 1) . ". {$step}";
        }

        $lines[] = '';
        $lines[] = '## Commands';
        $lines[] = '';

        foreach ($document['commands'] as $command) {
            $lines[] = "- `{$command['name']} --format=" . implode('|', $command['formats']) . "` — {$command['effect']}";
        }

        $lines[] = '';
        $lines[] = '## Launcher';
        $lines[] = '';
        $lines[] = $document['launcher'];
        $lines[] = '';
        $lines[] = '## Installed Kinetis packages';
        $lines[] = '';

        foreach ($document['packages'] as $package) {
            $lines[] = "- `{$package['name']}` {$package['version']}";
        }

        return implode("\n", $lines) . "\n";
    }
}
