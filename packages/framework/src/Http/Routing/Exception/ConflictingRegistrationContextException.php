<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A controller class is registered a second time under a different
 * global-middleware context than the one its already-committed routes
 * were built under — see `Router::register()`'s own doc comment for why
 * silently no-opping this would be a lie about which context actually
 * produced the routes currently in the router.
 */
final class ConflictingRegistrationContextException extends RuntimeException
{
    public static function forClass(string $controllerClass): self
    {
        return new self(sprintf(
            '"%s" is already registered under a different global-middleware context. '
            . 'Registering the same class twice is only a safe no-op when both calls '
            . 'pass the exact same global-middleware list.',
            $controllerClass,
        ));
    }

    public static function forCachedClass(string $controllerClass): self
    {
        return new self(sprintf(
            '"%s" was loaded from a compiled cache artifact, whose routes carry no record '
            . 'of the global-middleware context they were originally built under. Registering '
            . 'it again live cannot be verified as compatible with that unknown context, so it '
            . 'is rejected rather than silently trusted.',
            $controllerClass,
        ));
    }
}
