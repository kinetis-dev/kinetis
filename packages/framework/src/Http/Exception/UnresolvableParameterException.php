<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use RuntimeException;

final class UnresolvableParameterException extends RuntimeException
{
    public static function forParameter(string $name): self
    {
        return new self(
            "Cannot resolve parameter \"\${$name}\": no #[Body]/#[Query] attribute, "
            . 'matching path parameter, or default value was found.'
        );
    }
}
