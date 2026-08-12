<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param list<string> $allowedMethods exposed so Kernel can build a
     *   real RFC 9110 `Allow` header on the resulting 405, not just this
     *   exception's own human-readable message.
     */
    public function __construct(
        string $message,
        public readonly array $allowedMethods,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function forPath(string $path, array $allowedMethods): self
    {
        $allowedMethods = array_values(array_unique($allowedMethods));
        $methods = implode(', ', $allowedMethods);

        return new self("Path \"{$path}\" does not support this HTTP method. Allowed: {$methods}.", $allowedMethods);
    }
}
