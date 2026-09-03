<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An AppScope::bind(LoggerInterface::class, ...) factory that succeeds
 * once (for whatever eagerly resolves LoggerInterface at construction
 * time — ExceptionHandlerMiddleware, for one) and throws on every
 * resolution after that — proving a disposal-failure log call surviving
 * needs more than SafeLogger::log()'s own containment: the *resolution*
 * itself (SafeLogger::logFrom(), not log()) has to be covered too, since
 * a real AppScope binding can throw on a later call even when an earlier
 * one for the identical id succeeded.
 *
 * Must be registered with `$app->bind(LoggerInterface::class, $this, shared:
 * false)` — shared caching would let the first successful resolution's
 * instance answer every later call too, never reaching this class's own
 * second invocation at all.
 */
final class ThrowsAfterFirstResolutionLogger
{
    private int $calls = 0;

    public function __invoke(): LoggerInterface
    {
        $this->calls++;

        if ($this->calls === 1) {
            return new NullLogger();
        }

        throw new RuntimeException('logger factory failed');
    }
}
