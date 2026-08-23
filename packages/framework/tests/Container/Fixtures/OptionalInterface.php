<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Deliberately unregistered and unimplemented anywhere in the test
 * fixtures — proves an optional interface-typed dependency falls back to
 * its default instead of throwing NotFoundException.
 */
interface OptionalInterface {}
