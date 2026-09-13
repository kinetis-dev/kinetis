<?php

declare(strict_types=1);

namespace Kinetis\Views\Exception;

use RuntimeException;

final class ViewNotFoundException extends RuntimeException
{
    public static function named(string $view): self
    {
        return new self("View '{$view}' was not found.");
    }
}
