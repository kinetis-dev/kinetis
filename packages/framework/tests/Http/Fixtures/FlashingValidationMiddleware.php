<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Exception\ValidationException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware standing in for the workflow that needs a live
 * request scope — storing flash errors before redirecting, which a
 * session can only do while it is still open. It catches the
 * ValidationException Dispatcher now lets escape, so the terminal
 * renderer never sees it.
 */
final readonly class FlashingValidationMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            return new Response(303, [
                'Location' => '/form',
                'X-Flashed-Fields' => implode(',', array_keys($e->grouped())),
            ]);
        }
    }
}
