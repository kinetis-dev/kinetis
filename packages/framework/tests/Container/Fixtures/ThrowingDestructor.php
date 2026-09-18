<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

use RuntimeException;

/**
 * A retained service whose destructor throws. Releasing a scope's state
 * is what runs it: replacing the property that holds the last reference
 * destroys the object, and PHP surfaces the destructor's exception from
 * that assignment.
 *
 * Not a contrived shape — the AMQP client `kinetis/queue-rabbitmq`
 * installs disconnects from its own PHP 8.4 destructor and re-raises a
 * failed connection, endpoint included, from there.
 */
final class ThrowingDestructor
{
    public function __construct(private readonly string $message) {}

    public function __destruct()
    {
        throw new RuntimeException($this->message);
    }
}
