<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Sections;

/**
 * The order in which the fixture sections finished compiling.
 */
final class SectionLog
{
    /** @var list<class-string> */
    public static array $compiled = [];
}
