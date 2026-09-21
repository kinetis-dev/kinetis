<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

final class AdminKeyMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public const string SCHEME = 'adminKey';

    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(self::SCHEME, [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Admin-Key',
        ]);
    }
}
