<?php

declare(strict_types=1);

namespace Kinetis\Runtime\Adapters;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\SuperglobalsBridge;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The classic "boot and die" driver: one request, from superglobals, per
 * process. Not Kinetis's optimization target, but the Kernel doesn't care —
 * this adapter exists so the same application code runs unmodified under
 * PHP-FPM/CGI while a persistent runtime isn't available or isn't wanted.
 */
final class FpmAdapter implements RuntimeAdapterInterface
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
        SuperglobalsBridge::handle($handler, $this->limits, $this->trustedProxies);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return false;
    }
}
