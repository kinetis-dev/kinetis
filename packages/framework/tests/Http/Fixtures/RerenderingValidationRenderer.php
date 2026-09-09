<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Http\ValidationExceptionRendererInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An application renderer that re-renders the submitted page with the
 * violations inline and deliberately keeps a 200 — the other end of
 * the status range the default 422 sits in. It reads segmented paths
 * and codes rather than messages, which is what a page owning its own
 * wording needs.
 */
final readonly class RerenderingValidationRenderer implements ValidationExceptionRendererInterface
{
    #[\Override]
    public function render(ValidationException $exception, ServerRequestInterface $request): ResponseInterface
    {
        $items = array_map(
            static fn (Violation $violation): string => '<li>' . implode('.', $violation->path) . ': ' . $violation->code . '</li>',
            $exception->violations,
        );

        return HtmlResponse::create('<ul>' . implode('', $items) . '</ul>');
    }
}
