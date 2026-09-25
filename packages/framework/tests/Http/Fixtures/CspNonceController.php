<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Http\Responses\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CspNonceController
{
    #[Get('/csp-nonce')]
    public function page(ServerRequestInterface $request): ResponseInterface
    {
        $nonce = $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE);

        return HtmlResponse::create("<script nonce=\"{$nonce}\">start();</script>");
    }
}
