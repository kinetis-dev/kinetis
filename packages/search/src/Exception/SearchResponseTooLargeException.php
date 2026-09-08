<?php

declare(strict_types=1);

namespace Kinetis\Search\Exception;

use RuntimeException;

/**
 * A response body that passed the configured bound. The transfer is
 * abandoned where this is thrown, part-way through the body and before
 * the rest of it is buffered into the worker.
 *
 * It is a cause rather than an outcome: the HTTP client treats the throw
 * as an abort and reports it as a transport failure, which
 * {@see \Kinetis\Search\BufferedHttpClient} turns into the
 * {@see SearchNetworkException} a call meets. This one stays under it,
 * naming the configuration key that ended the transfer.
 */
final class SearchResponseTooLargeException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("The search response passed {$key}.");
    }
}
