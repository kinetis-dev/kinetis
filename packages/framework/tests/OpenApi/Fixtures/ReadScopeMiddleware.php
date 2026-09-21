<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/** The same scheme as WriteScopeMiddleware, required with other scopes. */
final class ReadScopeMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(
            WriteScopeMiddleware::SCHEME,
            WriteScopeMiddleware::DEFINITION,
            ['audit', 'read'],
        );
    }
}
