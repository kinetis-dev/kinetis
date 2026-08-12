<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

final class DocsResourceException extends RuntimeException
{
    public static function missingPage(string $slug, string $path): self
    {
        return new self("Kinetis docs page \"{$slug}\" is missing at {$path} — is docs/ present in this install?");
    }
}
