<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

/**
 * Application-scoped record of what the fixture commands, listener and
 * request-scoped unit of work did, in order.
 */
final class Journal
{
    /** @var list<string> */
    public array $entries = [];
}
