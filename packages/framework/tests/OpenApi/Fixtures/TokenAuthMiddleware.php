<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;

/**
 * Records its run like any other RecordingMiddleware, so one route can
 * be checked against both what it dispatched and what it published.
 * Not final: GroupedTokenAuthMiddleware is the subclass that stands in
 * for the thin group member an application writes.
 */
class TokenAuthMiddleware extends RecordingMiddleware implements SecurityDescriberInterface
{
    public const string SCHEME = 'token';

    /** @var array<string, mixed> */
    public const array DEFINITION = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Token'];

    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(self::SCHEME, self::DEFINITION);
    }
}
