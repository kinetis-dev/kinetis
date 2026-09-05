<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Client\RequestExceptionInterface;

/**
 * A failure PSR-18 classifies as being about the request itself: it is
 * not the sort of thing repeating the call would fix. An off-origin
 * target, credentials that cannot be resolved, a body that cannot be
 * read, a signature that cannot be computed, and a transport rejecting
 * the request before it goes anywhere all land here.
 *
 * Not `final` — it exists to be extended by the concrete failures in
 * this namespace, each `final` with its own fixed message. See
 * {@see ClientFailureException} for the request, message, cause, and
 * serialization rules every failure here shares.
 */
abstract class RequestFailureException extends ClientFailureException implements RequestExceptionInterface {}
