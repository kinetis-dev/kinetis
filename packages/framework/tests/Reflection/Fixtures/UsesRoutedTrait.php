<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

/** Shares the routed method through a trait rather than a parent. */
final class UsesRoutedTrait
{
    use RoutedTrait;
}
