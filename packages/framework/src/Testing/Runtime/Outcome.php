<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

/**
 * Everything a driver reports back from one dispatch: what the handler
 * saw (`null` when the adapter never reached it) and what left the
 * adapter.
 */
final readonly class Outcome
{
    public function __construct(
        public ?ObservedRequest $observed,
        public WireResponse|AdapterRejection $response,
    ) {}
}
