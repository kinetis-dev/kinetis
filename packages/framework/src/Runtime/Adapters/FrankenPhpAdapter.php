<?php

declare(strict_types=1);

namespace Kinetis\Runtime\Adapters;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\SuperglobalsBridge;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Kinetis's primary optimization target: keeps AppScope warm for the life
 * of the worker process and calls frankenphp_handle_request() in a loop,
 * once per request, rather than exiting after each one. FrankenPHP
 * repopulates superglobals/php://input on each iteration the same way a
 * classic SAPI would, so request/response conversion reuses
 * SuperglobalsBridge rather than duplicating FpmAdapter's logic.
 */
final class FrankenPhpAdapter implements RuntimeAdapterInterface
{
    public function __construct(
        private readonly FormLimits $limits,
        private readonly TrustedProxies $trustedProxies,
    ) {}

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    #[\Override]
    public function run(callable $handler): void
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw RuntimeUnavailableException::missingFunction(self::class, 'frankenphp_handle_request');
        }

        do {
            $keepRunning = frankenphp_handle_request(function () use ($handler): void {
                SuperglobalsBridge::handle($handler, $this->limits, $this->trustedProxies);
            });
        } while ($keepRunning);
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return true;
    }
}
