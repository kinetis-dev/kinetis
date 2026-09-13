<?php

declare(strict_types=1);

namespace Kinetis\Views\Exception;

use RuntimeException;
use Throwable;

final class ViewRenderException extends RuntimeException
{
    public static function from(string $view, Throwable $previous): self
    {
        return new self("View '{$view}' could not be rendered.", 0, $previous);
    }
}
