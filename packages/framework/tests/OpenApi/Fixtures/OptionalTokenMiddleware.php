<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/** Reads a token when one is present and admits the request when it is not. */
final class OptionalTokenMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public static function openApiSecurity(): SecurityDescription
    {
        return new SecurityDescription(
            [TokenAuthMiddleware::SCHEME => TokenAuthMiddleware::DEFINITION],
            [[TokenAuthMiddleware::SCHEME => []], []],
        );
    }
}
