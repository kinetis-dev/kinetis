<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Linting\Fixtures;

/**
 * Built-in classes whose constructors PHPStan describes with more than one
 * signature variant.
 */
final class BuiltInConstructorCalls
{
    public function run(): void
    {
        new \ReflectionMethod(self::class, 'run');
        new \ReflectionMethod(self::class . '::run');
    }
}
