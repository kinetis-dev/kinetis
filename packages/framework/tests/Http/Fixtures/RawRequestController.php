<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Patch;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Put;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RawRequestController
{
    /**
     * Kept to this exact shape deliberately — {@see \Kinetis\Tests\Http\DispatcherTest}
     * asserts it byte-for-byte to prove a ServerRequestInterface-typed
     * parameter receives the raw request, a different concern from
     * echo()'s own, below. Query-string/params consistency is exercised
     * through echo() instead, via request()'s own $query parameter.
     */
    #[Get('/raw-request')]
    public function show(ServerRequestInterface $request): array
    {
        return ['path' => $request->getUri()->getPath(), 'method' => $request->getMethod()];
    }

    /**
     * The one echo endpoint {@see \Kinetis\Tests\Testing\TestClientTest}
     * exercises every TestClient request-building mode through — every
     * PSR-7 property a caller might need to observe, so a test can pin
     * exactly what bytes/headers/parsed data actually crossed the wire,
     * not just that a response came back. rawBody is base64-encoded
     * specifically so a binary body round-trips through this JSON
     * response without json_encode() itself corrupting it — the raw
     * bytes may not be valid UTF-8 at all.
     */
    #[Post('/raw-request')]
    #[Put('/raw-request')]
    #[Patch('/raw-request')]
    public function echo(ServerRequestInterface $request): array
    {
        return [
            'contentType' => $request->getHeaderLine('Content-Type'),
            'rawBodyBase64' => \base64_encode((string) $request->getBody()),
            'parsedBody' => $request->getParsedBody(),
            'queryString' => $request->getUri()->getQuery(),
            'queryParams' => $request->getQueryParams(),
        ];
    }
}
