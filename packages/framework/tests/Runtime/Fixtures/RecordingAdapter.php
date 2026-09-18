<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures;

use Kinetis\Http\TrustedProxies;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stands in for a detected runtime adapter: keeps the proxy policy it
 * was handed, and serves the one request it was built with — if any —
 * through the handler while `run()` is still active, instead of
 * entering a request loop that never returns.
 *
 * The handler is deliberately not kept past `run()`. A real loop only
 * ever calls it from inside itself, and `HttpStartup::serve()` disposes
 * the application the moment the loop returns, so a handler invoked
 * afterwards would be answering from a container that no longer exists.
 */
final class RecordingAdapter implements RuntimeAdapterInterface
{
    public ?ResponseInterface $response = null;

    public function __construct(
        public readonly TrustedProxies $trustedProxies,
        private readonly bool $persistent = false,
        private readonly ?ServerRequestInterface $request = null,
    ) {}

    #[\Override]
    public function run(callable $handler): void
    {
        if ($this->request !== null) {
            $this->response = $handler($this->request);
        }
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return $this->persistent;
    }
}
