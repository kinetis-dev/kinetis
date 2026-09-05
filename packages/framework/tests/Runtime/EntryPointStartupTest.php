<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime;

use Kinetis\Testing\Runtime\EntryPointStartupTestCase;

/**
 * The reference `public/index.php` this framework ships, held to the
 * same startup order every application copying it is held to.
 */
final class EntryPointStartupTest extends EntryPointStartupTestCase
{
    #[\Override]
    protected function entryPoint(): string
    {
        return dirname(__DIR__, 2) . '/public/index.php';
    }
}
