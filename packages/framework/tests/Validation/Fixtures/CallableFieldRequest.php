<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * `callable` is a legal *parameter* type in PHP but not a legal
 * *property* type at all (promoted or plain) — confirmed directly, not
 * assumed: `public callable $handler` in a constructor's promotion list
 * fails to compile ("Property ... cannot have type callable"). So this
 * fixture's own property is deliberately typed `mixed`, assigned by hand
 * in the constructor body, while the constructor *parameter* itself
 * stays `callable` — the actual type Hydrator/JsonSchema reflect on.
 */
final readonly class CallableFieldRequest
{
    public mixed $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }
}
