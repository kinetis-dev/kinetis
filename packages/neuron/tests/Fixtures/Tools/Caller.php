<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Tools;

/** Stands in for a request-scoped identity: the test binds the real one on the request scope only. */
final class Caller
{
    public function __construct(public string $name = 'nobody') {}
}
