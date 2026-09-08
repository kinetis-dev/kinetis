<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Nothing binds this interface unless a test does, and no fixture
 * implements it: a parameter typed against it is absent.
 */
interface OptionalInterface {}
