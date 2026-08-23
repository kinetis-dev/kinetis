<?php

declare(strict_types=1);

namespace Kinetis\Console\Attributes;

use Attribute;

/**
 * Exposes a controller method as a named CLI command, invoked via
 * `vendor/bin/kinetis <name>` — the console analogue of #[McpTool]. The
 * method must declare zero parameters, or exactly one parameter typed
 * CommandArguments; CommandRegistry::register() validates this at
 * registration time.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Command
{
    public function __construct(
        public string $name,
        public string $description = '',
        /**
         * Whether bin/kinetis executes the application's bootstrap.php
         * (and boots AppScope with everything it registers) before
         * dispatching this command. Commands that only operate on the
         * project's static shape — compiling caches, printing metadata —
         * set this to false so they run without the configuration the
         * application's own services demand (a CI pipeline pre-warming
         * caches has no database credentials, and must not need them).
         */
        public bool $bootstrap = true,
    ) {}
}
