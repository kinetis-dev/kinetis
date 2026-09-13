<?php

declare(strict_types=1);

namespace Kinetis\Views;

use InvalidArgumentException;
use Kinetis\Http\Responses\HtmlResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class Views
{
    public function __construct(private ViewEngineInterface $engine) {}

    /** @param array<array-key, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        foreach ($data as $name => $_value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('View data must use string keys.');
            }

            if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name) !== 1) {
                throw new InvalidArgumentException("View data keys must be valid template variable names.");
            }

            if (in_array($name, ['asset', 'GLOBALS', 'this'], true)) {
                throw new InvalidArgumentException("View data key '{$name}' is reserved.");
            }
        }

        return $this->engine->render(new ViewName($view), $data);
    }

    /** @param array<array-key, mixed> $data */
    public function response(string $view, array $data = [], int $status = 200): ResponseInterface
    {
        return HtmlResponse::create($this->render($view, $data), $status);
    }
}
