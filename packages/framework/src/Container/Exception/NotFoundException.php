<?php

declare(strict_types=1);

namespace Kinetis\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self("No entry was found for identifier \"{$id}\".");
    }
}
