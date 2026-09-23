<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use Amp\Cancellation;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\StreamedContent;

/**
 * Hands Amp the `Content-Length` AsyncAws already declared for a resource
 * body.
 *
 * AsyncAws measures a resource body with fstat(), signs it and sets
 * `Content-Length` to its full size, and reads it from offset zero.
 * Symfony's AmpHttpClient receives that body as an iterable and hands Amp
 * a body of unknown length, so Amp's HTTP/1.1 connection drops the header
 * and sends the body chunked, which S3 refuses with
 * `411 MissingContentLength`. Here the declared length is restored to the
 * same body stream: nothing is buffered, copied or closed, and HTTP/1.1
 * aborts the request when the body turns out shorter or longer.
 *
 * The body stream is consumed once. That holds only because the S3
 * transport follows no redirect and installs no retry.
 */
final class DeclaredContentLength implements NetworkInterceptor
{
    #[\Override]
    public function requestViaNetwork(Request $request, Cancellation $cancellation, Stream $stream): Response
    {
        $length = $request->getHeader('content-length');
        $body = $request->getBody();

        if ($length !== null && ctype_digit($length) && $body->getContentLength() === null) {
            $request->setBody(StreamedContent::fromStream($body->getContent(), (int) $length));
        }

        return $stream->request($request, $cancellation);
    }
}
