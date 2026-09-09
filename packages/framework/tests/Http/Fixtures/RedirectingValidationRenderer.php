<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\ValidationExceptionRendererInterface;
use Kinetis\Validation\Exception\ValidationException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An application renderer answering a validation failure the way a
 * server-rendered form does: a 303 back to the path the request came
 * from, with the failed fields in a header this suite can read. Proves
 * the terminal boundary imposes no status range of its own.
 */
final readonly class RedirectingValidationRenderer implements ValidationExceptionRendererInterface
{
    #[\Override]
    public function render(ValidationException $exception, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(303, [
            'Location' => $request->getUri()->getPath(),
            'X-Failed-Fields' => implode(',', array_keys($exception->grouped())),
        ]);
    }
}
