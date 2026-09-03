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
         * Whether bin/kinetis runs the package-then-application
         * bootstrap chain — every installed package's own
         * PackageBootstrapInterface::register() as well as the
         * project's bootstrap.php, both of which register services on
         * AppScope — before dispatching this command. This is the only
         * thing the flag gates: the command itself is always looked up
         * from the discovered/cached command registry regardless (that
         * happens before this flag is even consulted, since bin/kinetis
         * needs the command's own definition to read this flag off it
         * in the first place), and the discovered/cached
         * EventListenerRegistry and plugin-discovery data always bind
         * to AppScope either way. Commands that only operate on the
         * project's static shape — compiling caches, printing metadata
         * — set this to false so they run without the configuration any
         * of the bootstrap chain's own registrations might demand (a CI
         * pipeline pre-warming caches has no database credentials, and
         * must not need them).
         */
        public bool $bootstrap = true,
    ) {}
}
