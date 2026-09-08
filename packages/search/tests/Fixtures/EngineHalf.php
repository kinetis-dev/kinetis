<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests\Fixtures;

use Closure;
use Kinetis\Search\AbstractSearchClient;
use Kinetis\Search\SearchCall;

/**
 * An adapter's engine half, supplied by the test instead of by an engine
 * client: what the closure receives is exactly what
 * {@see AbstractSearchClient} assembled, and what it answers or throws is
 * what a cluster would have.
 */
final readonly class EngineHalf extends AbstractSearchClient
{
    /** @param Closure(SearchCall, array<string, mixed>): array<string, mixed> $engine */
    public function __construct(private Closure $engine)
    {
    }

    #[\Override]
    protected function send(SearchCall $call, array $params): array
    {
        return ($this->engine)($call, $params);
    }
}
