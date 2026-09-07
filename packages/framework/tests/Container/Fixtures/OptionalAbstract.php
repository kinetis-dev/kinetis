<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Unregistered and unextended anywhere in the fixtures: an abstract class
 * is never autowireable on its own, so a parameter typed against one is
 * absent until something binds it.
 */
abstract class OptionalAbstract
{
    abstract public function label(): string;
}
