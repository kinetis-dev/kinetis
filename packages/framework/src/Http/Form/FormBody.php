<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Closure;
use Kinetis\Http\MediaType;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request's raw bytes into the two structures PSR-7 hands a
 * handler — `getParsedBody()` and `getUploadedFiles()` — for the two
 * media types that arrive parsed rather than raw. The one entry point
 * every runtime adapter uses, so "what is a form body, and what does
 * this one mean" is answered once rather than once per runtime.
 *
 * The byte ceiling is met here too, against the bytes actually in hand
 * as well as any declared `Content-Length`: a request that understates
 * its length is bounded only by the first, and one that overstates it is
 * refused without being parsed at all. Under a SAPI the same instance has
 * already bounded the same bytes while staging them, and checking a
 * number that is already known to be under its ceiling costs nothing.
 *
 * $parseMultipart is the one seam. Core parses `multipart/form-data`
 * with {@see MultipartEnvelope} and {@see MultipartFormBuilder}; the
 * satellite adapters hand the body to `riverline/multipart-parser`
 * instead, because a Lambda or RoadRunner worker has no live
 * `php://input` behind the body and pulling that dependency into every
 * Kinetis install for a deployment target most consumers never use is
 * not worth it. What the two share is the contract in
 * {@see MultipartEnvelope}, which every runtime enforces over the raw
 * bytes before either parser runs — that is what makes the seam a
 * choice of parser rather than a second dialect.
 */
final class FormBody
{
    /**
     * @param ?int $declaredBytes the `Content-Length` header when it
     *     carried a non-negative integer, null otherwise
     * @param ?Closure(string, string, FormLimits): array{0: array<array-key, mixed>, 1: array<array-key, mixed>} $parseMultipart
     *     receives the content type, the raw body and the limits;
     *     {@see parseMultipart()} when omitted
     */
    public static function apply(
        ServerRequestInterface $request,
        string $body,
        ?int $declaredBytes,
        FormLimits $limits,
        ?Closure $parseMultipart = null,
    ): ServerRequestInterface {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!MediaType::isFormEncoded($contentType)) {
            return $request;
        }

        $limits->assertBodyWithinLimit(strlen($body), $declaredBytes);

        if (!MediaType::isMultipartFormData($contentType)) {
            return $request->withParsedBody(UrlEncodedForm::parse($body, $limits));
        }

        [$parsedBody, $uploadedFiles] = ($parseMultipart ?? self::parseMultipart(...))($contentType, $body, $limits);

        return $request->withParsedBody($parsedBody)->withUploadedFiles($uploadedFiles);
    }

    /**
     * The framework's own multipart parse: the envelope's own parts,
     * built into both structures by the shared builder. An unnamed part
     * builds neither — it was counted during the scan, which is the only
     * place it can be.
     *
     * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    public static function parseMultipart(string $contentType, string $body, FormLimits $limits): array
    {
        $builder = new MultipartFormBuilder($limits);

        foreach (MultipartEnvelope::parts($body, $contentType, $limits) as $part) {
            if ($part->name === null) {
                continue;
            }

            if ($part->isFile()) {
                $builder->addFile($part->name, $part->filename, $part->contentType, $part->body);

                continue;
            }

            $builder->addField($part->name, $part->body);
        }

        return $builder->build();
    }
}
