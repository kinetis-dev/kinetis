<?php

declare(strict_types=1);

namespace Kinetis\Search\Exception;

use RuntimeException;
use Throwable;

/**
 * An error status the cluster answered a {@see \Kinetis\Search\SearchClient}
 * call with: a rejected query, a version conflict, a closed index, a
 * cluster that could not serve the request. It is the one failure the
 * engine-neutral client reports for a request that reached the cluster,
 * so an application handling it does not name an engine's own exception
 * class.
 *
 * The engine's own exception is the previous one and carries the
 * cluster's own error text. An application using the engine client
 * directly meets that exception instead, unmapped.
 *
 * A missing document is not one of these: {@see \Kinetis\Search\SearchClient::get()}
 * answers null and {@see \Kinetis\Search\SearchClient::delete()} answers
 * false.
 */
final class SearchRequestException extends RuntimeException
{
    private function __construct(
        public readonly int $status,
        Throwable $previous,
    ) {
        parent::__construct("The search cluster answered {$status}.", 0, $previous);
    }

    public static function status(int $status, Throwable $previous): self
    {
        return new self($status, $previous);
    }
}
