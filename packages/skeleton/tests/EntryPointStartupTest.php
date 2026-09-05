<?php

declare(strict_types=1);

namespace App\Tests;

use Kinetis\Testing\Runtime\EntryPointStartupTestCase;

/**
 * This application's own `public/index.php`, held to the framework's
 * startup order rather than assumed to still match the reference copy
 * it started as.
 */
final class EntryPointStartupTest extends EntryPointStartupTestCase
{
    #[\Override]
    protected function entryPoint(): string
    {
        return dirname(__DIR__) . '/public/index.php';
    }
}
