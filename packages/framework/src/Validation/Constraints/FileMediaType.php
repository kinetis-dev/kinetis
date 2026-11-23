<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use finfo;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The media types an uploaded file's *content* may be detected as.
 *
 * The type is detected from the bytes by the host's libmagic through
 * `ext-fileinfo`. The client-supplied media type and filename play no
 * part: both are written by the client and describe nothing the server
 * has observed.
 *
 * The rule reads the whole part. The supported runtimes hand the
 * framework every multipart part as an in-memory stream already bounded
 * by the body limit, and the rule rewinds that stream before and after
 * reading it, so the handler receives the stream at offset 0. A stream
 * that cannot be opened, rewound, or read throws, and so does a libmagic
 * that cannot answer: neither is a property of the request.
 *
 * Each declared type is an exact `type/subtype` in RFC 6838's
 * restricted-name grammar, with no parameters and no wildcard, because
 * the detected value has neither. Comparison is ASCII case-insensitive.
 * A detection that matches none of them fails with the declared choices
 * and the detected type, which the server produced and may therefore be
 * echoed.
 *
 * {@see schema()} publishes nothing: an OpenAPI `encoding.contentType`
 * describes the media type a client declares, not the one detected from
 * its bytes.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class FileMediaType implements Constraint
{
    /**
     * RFC 6838 section 4.2 `restricted-name "/" restricted-name`,
     * anchored at both ends with `D` so a trailing newline cannot pass
     * for the end of the subject.
     */
    private const string MEDIA_TYPE_PATTERN
        = '~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}$~D';

    /**
     * @param list<string> $types
     */
    public function __construct(
        private array $types,
    ) {
        if ($types === [] || !array_is_list($types)) {
            throw new InvalidArgumentException('FileMediaType types must be a non-empty list of strings.');
        }

        foreach ($types as $type) {
            if (!is_string($type) || preg_match(self::MEDIA_TYPE_PATTERN, $type) !== 1) {
                throw new InvalidArgumentException(
                    'FileMediaType types must be exact type/subtype media types without parameters or wildcards, '
                    . 'such as "image/png", ' . var_export($type, true) . ' given.',
                );
            }
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!$value instanceof UploadedFileInterface) {
            return new Violation([], 'not_a_file', 'must be an uploaded file.');
        }

        $stream = $value->getStream();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->rewind();

        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($contents);

        if ($detected === false) {
            throw new RuntimeException('FileMediaType could not detect a media type: libmagic returned no answer.');
        }

        // strtolower() is ASCII-only from PHP 8.2 on, the locale-free
        // comparison the docblock promises.
        $detected = strtolower($detected);

        foreach ($this->types as $type) {
            if ($detected === strtolower($type)) {
                return null;
            }
        }

        return new Violation(
            [],
            'file_media_type',
            'must be one of these media types: ' . implode(', ', $this->types) . '.',
            ['choices' => $this->types, 'media_type' => $detected],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
