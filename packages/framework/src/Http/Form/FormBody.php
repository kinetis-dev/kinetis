<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Kinetis\Http\MediaType;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request's raw bytes into the two structures PSR-7 hands a
 * handler — `getParsedBody()` and `getUploadedFiles()` — for the two
 * media types that arrive parsed rather than raw. Called from
 * {@see \Kinetis\Http\Middleware\RequestBodyMiddleware} and nowhere
 * else, so "what is a form body, and what does this one mean" is
 * answered once for every runtime rather than once per runtime.
 *
 * The body arrives already staged and already under its byte ceiling, so
 * this reads it in full and rewinds it: every count and every rule that
 * bounds the parse is applied to the raw bytes, because that is the only
 * place the real numbers exist. A thousand repetitions of one name are a
 * thousand pairs on the wire and one leaf afterwards, and a part carrying
 * no name at all costs a parser everything and appears nowhere in the
 * result.
 *
 * The body stays readable afterwards, rewound and complete — a handler
 * that wants the raw form bytes as well as the parsed structure gets
 * both.
 */
final class FormBody
{
    public static function apply(ServerRequestInterface $request, FormLimits $limits): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!MediaType::isFormEncoded($contentType)) {
            return $request;
        }

        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        if (!MediaType::isMultipartFormData($contentType)) {
            return $request->withParsedBody(UrlEncodedForm::parse($body, $limits));
        }

        [$parsedBody, $uploadedFiles] = self::parseMultipart($contentType, $body, $limits);

        return $request->withParsedBody($parsedBody)->withUploadedFiles($uploadedFiles);
    }

    /**
     * The envelope's own parts, built into both structures by the shared
     * builder. An unnamed part builds neither — it was counted during the
     * scan, which is the only place it can be.
     *
     * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    private static function parseMultipart(string $contentType, string $body, FormLimits $limits): array
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
