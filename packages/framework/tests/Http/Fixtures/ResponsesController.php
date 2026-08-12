<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Http\Responses\RedirectResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class ResponsesController
{
    #[Get('/page')]
    public function page(): ResponseInterface
    {
        return HtmlResponse::create('<h1>Hello</h1>');
    }

    #[Get('/avatar')]
    public function avatar(): ResponseInterface
    {
        return FileResponse::fromContents('not-really-a-png', 'image/png', downloadFilename: 'avatar.png');
    }

    #[Get('/old-page')]
    public function oldPage(): ResponseInterface
    {
        return RedirectResponse::to('/new-page', 301);
    }
}
