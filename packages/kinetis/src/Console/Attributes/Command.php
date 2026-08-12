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
    ) {}
}
