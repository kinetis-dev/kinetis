<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RawRequestController
{
    #[Get('/raw-request')]
    public function show(ServerRequestInterface $request): array
    {
        return ['path' => $request->getUri()->getPath(), 'method' => $request->getMethod()];
    }

    #[Post('/raw-request')]
    public function echo(ServerRequestInterface $request): array
    {
        return ['contentType' => $request->getHeaderLine('Content-Type')];
    }
}
