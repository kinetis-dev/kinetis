<?php

declare(strict_types=1);

namespace Kinetis\Http;

/**
 * Classifies a Content-Type header value by the media type it names —
 * the one place every request-body path in this framework decides
 * whether a body is form-encoded or JSON.
 *
 * The comparison is exact on the type/subtype and
 * ASCII-case-insensitive, which is what RFC 9110 §8.3.1 requires:
 * `Application/X-WWW-Form-Urlencoded; charset=UTF-8` names the same
 * media type as `application/x-www-form-urlencoded` and is read as a
 * form body, while `application/x-www-form-urlencodedevil` names a
 * different one and is not.
 *
 * {@see of()} is the single parse behind every predicate: everything
 * before the first `;`, surrounding whitespace removed, lowercased
 * through strtolower(), which is ASCII-only and locale-independent — a
 * Turkish locale can't turn an `I` into a dotless one here. The
 * parameters after that `;` are not this class's to read:
 * {@see \Kinetis\Http\Form\MultipartEnvelope} holds them to one strict
 * grammar, because a `boundary` decides where a body's parts are and two
 * parsers must not find two different answers in one header.
 */
final class MediaType
{
    public const string FORM_URLENCODED = 'application/x-www-form-urlencoded';

    public const string MULTIPART_FORM_DATA = 'multipart/form-data';

    public const string JSON = 'application/json';

    /**
     * The bare media type a Content-Type header value names, ready to
     * compare against a lowercase literal. A request carrying no
     * Content-Type at all — `getHeaderLine()` returning `''` — yields
     * `''`, which matches nothing here.
     */
    public static function of(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * The two media types a request body reaches an application in
     * already parsed, as getParsedBody()/getUploadedFiles() rather than
     * raw bytes.
     */
    public static function isFormEncoded(string $contentType): bool
    {
        $mediaType = self::of($contentType);

        return $mediaType === self::FORM_URLENCODED || $mediaType === self::MULTIPART_FORM_DATA;
    }

    public static function isMultipartFormData(string $contentType): bool
    {
        return self::of($contentType) === self::MULTIPART_FORM_DATA;
    }

    /**
     * The media types whose body is a JSON document: `application/json`
     * itself, and any `application/` subtype carrying RFC 6839's `+json`
     * structured suffix (`application/vnd.api+json`,
     * `application/hal+json`). The suffix needs a subtype in front of it,
     * so `application/+json` names nothing here; `text/json` is a
     * different top-level type and is not JSON to this framework.
     *
     * {@see \Kinetis\Http\Dispatcher} reads a `#[Body]` DTO's raw bytes
     * as JSON only when this answers true, so a media type nothing here
     * recognizes is refused rather than guessed at.
     */
    public static function isJson(string $contentType): bool
    {
        $mediaType = self::of($contentType);

        if ($mediaType === self::JSON) {
            return true;
        }

        $prefix = 'application/';

        if (!str_starts_with($mediaType, $prefix)) {
            return false;
        }

        $subtype = substr($mediaType, strlen($prefix));

        return strlen($subtype) > strlen('+json') && str_ends_with($subtype, '+json');
    }

    /**
     * Any `multipart/*` media type, not just the form one — what a
     * `multipart/form-data` part may never itself declare. See
     * {@see \Kinetis\Http\Form\MultipartEnvelope} for why a nested
     * envelope is refused rather than parsed.
     */
    public static function isMultipart(string $contentType): bool
    {
        return str_starts_with(self::of($contentType), 'multipart/');
    }
}
