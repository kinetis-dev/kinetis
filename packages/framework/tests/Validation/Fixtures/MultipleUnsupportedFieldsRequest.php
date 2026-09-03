<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * Both rejected builtin categories in one DTO — proves Hydrator's own
 * "all fields validated up front" promise (see its class docblock)
 * extends to object/callable rejection too: a request supplying values
 * for both must report both errors together in one response, not just
 * whichever field the internal loop happens to reach first.
 */
final readonly class MultipleUnsupportedFieldsRequest
{
    public mixed $handler;

    public function __construct(
        public object $extra,
        callable $handler,
    ) {
        $this->handler = $handler;
    }
}
