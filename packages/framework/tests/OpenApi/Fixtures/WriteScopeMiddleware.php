<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

final class WriteScopeMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public const string SCHEME = 'orders';

    /** @var array<string, mixed> */
    public const array DEFINITION = [
        'type' => 'oauth2',
        'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.test/token', 'scopes' => []]],
    ];

    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(self::SCHEME, self::DEFINITION, ['write', 'read']);
    }
}
