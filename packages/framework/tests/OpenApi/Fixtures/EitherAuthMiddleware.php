<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Two alternatives, either of which passes it. Its `token` definition is
 * TokenAuthMiddleware's own, so a document naming both publishes one
 * scheme.
 */
final class EitherAuthMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public static function openApiSecurity(): SecurityDescription
    {
        return new SecurityDescription(
            [
                TokenAuthMiddleware::SCHEME => TokenAuthMiddleware::DEFINITION,
                'session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'sid'],
            ],
            [
                [TokenAuthMiddleware::SCHEME => []],
                ['session' => []],
            ],
        );
    }
}
