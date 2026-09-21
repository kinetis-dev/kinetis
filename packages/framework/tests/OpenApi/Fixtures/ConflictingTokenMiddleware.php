<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/** TokenAuthMiddleware's scheme name over a different definition. */
final class ConflictingTokenMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(TokenAuthMiddleware::SCHEME, [
            'type' => 'apiKey',
            'in' => 'query',
            'name' => 'token',
        ]);
    }
}
