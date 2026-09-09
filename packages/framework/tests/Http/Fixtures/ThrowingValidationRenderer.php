<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\ValidationExceptionRendererInterface;
use Kinetis\Validation\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * A broken application renderer, for proving one cannot defeat the
 * terminal boundary: the request still gets the generic 500, and both
 * the validation failure being rendered and this exception reach the
 * logger.
 */
final readonly class ThrowingValidationRenderer implements ValidationExceptionRendererInterface
{
    public const string MESSAGE = 'the renderer itself is broken';

    #[\Override]
    public function render(ValidationException $exception, ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
